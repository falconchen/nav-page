<?php
/**
 * NPMplus 导航页 - 动态读取代理域名
 * 访问: https://nav.ubt.cellmean.com
 *
 * 数据源：优先使用 NPMplus API，失败则回退到数据库
 */

// 处理重启 action（AJAX 接口）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restart') {
    header('Content-Type: application/json');

    $restart_token = getenv('RESTART_TOKEN');
    $webhook_url   = getenv('WEBHOOK_URL');

    if (empty($restart_token) || empty($webhook_url)) {
        echo json_encode(['ok' => false, 'msg' => 'webhook not configured']);
        exit;
    }

    $submitted_token = $_POST['token'] ?? '';
    if (!hash_equals($restart_token, $submitted_token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'invalid token']);
        exit;
    }

    $ch = curl_init($webhook_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Token: ' . $restart_token]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        echo json_encode(['ok' => false, 'msg' => 'webhook unreachable: ' . $curl_err]);
        exit;
    }

    $result = json_decode($body, true);
    if (!is_array($result)) {
        echo json_encode(['ok' => false, 'msg' => 'invalid webhook response (HTTP ' . $http_code . ')']);
        exit;
    }

    echo json_encode($result);
    exit;
}

// 处理日志 action（AJAX 接口）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logs') {
    header('Content-Type: application/json');

    $restart_token = getenv('RESTART_TOKEN');
    $webhook_url   = getenv('WEBHOOK_URL');

    if (empty($restart_token) || empty($webhook_url)) {
        echo json_encode(['ok' => false, 'lines' => [], 'msg' => 'webhook not configured']);
        exit;
    }

    $submitted_token = $_POST['token'] ?? '';
    if (!hash_equals($restart_token, $submitted_token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'lines' => [], 'msg' => 'invalid token']);
        exit;
    }

    $logs_url = preg_replace('/\/[^\/]+$/', '/logs', $webhook_url);
    $ch = curl_init($logs_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Token: ' . $restart_token]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        echo json_encode(['ok' => false, 'lines' => [], 'msg' => 'webhook unreachable: ' . $curl_err]);
        exit;
    }

    $result = json_decode($body, true);
    if (!is_array($result)) {
        echo json_encode(['ok' => false, 'lines' => [], 'msg' => 'invalid webhook response (HTTP ' . $http_code . ')']);
        exit;
    }

    echo json_encode($result);
    exit;
}

// 加载配置
require_once __DIR__ . '/config.php';

$cookie_jar = '/tmp/npm_cookies.txt';

// 尝试从 API 获取数据
function getProxyHostsFromApi($api_url, $email, $password, $cookie_jar) {
    $sites = [];
    
    // 登录获取 Cookie
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url . '/api/tokens');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'identity' => $email,
        'secret' => $password
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_jar);
    curl_setopt($ch, CURLOPT_COOKIESESSION, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        return null; // 登录失败
    }
    
    // 获取代理主机列表
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url . '/api/nginx/proxy-hosts');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_jar);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        return null;
    }
    
    $hosts = json_decode($response, true);
    if (!is_array($hosts)) {
        return null;
    }
    
    foreach ($hosts as $host) {
        if ($host['enabled'] !== true) {
            continue; // 跳过禁用的主机
        }
        
        $domains = $host['domain_names'] ?? [];
        $primary_domain = $domains[0] ?? '';
        
        if (empty($primary_domain)) {
            continue;
        }
        
        $scheme = $host['forward_scheme'] ?? 'http';
        $host_addr = $host['forward_host'] ?? '127.0.0.1';
        $port = $host['forward_port'] ?? 80;
        
        // 构建 URL
        $url = $scheme . '://' . $host_addr;
        if (!in_array($port, [80, 443])) {
            $url .= ':' . $port;
        }
        
        // 从域名提取简短名称
        $name = preg_replace('/\.ubt\.cellmean\.com$/', '', $primary_domain);
        $name = ucfirst($name);
        
        $sites[] = [
            'id' => $host['id'],
            'name' => $name,
            'domain' => $primary_domain,
            'url' => $url
        ];
    }
    
    // 清理 Cookie
    @unlink($cookie_jar);
    
    return $sites;
}

// 从数据库获取数据（回退方案）
function getProxyHostsFromDb($db_path) {
    $sites = [];
    
    try {
        $pdo = new PDO("sqlite:$db_path");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->query("
            SELECT id, domain_names, forward_scheme, forward_host, forward_port 
            FROM proxy_host 
            WHERE is_deleted = 0 AND enabled = 1
            ORDER BY domain_names
        ");
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $domains = json_decode($row['domain_names'], true);
            $primary_domain = $domains[0] ?? '';
            
            if (empty($primary_domain)) {
                continue;
            }
            
            $scheme = $row['forward_scheme'];
            $host = $row['forward_host'];
            $port = $row['forward_port'];
            
            $url = $scheme . '://' . $host;
            if (!in_array($port, [80, 443])) {
                $url .= ':' . $port;
            }
            
            $name = preg_replace('/\.ubt\.cellmean\.com$/', '', $primary_domain);
            $name = ucfirst($name);
            
            $sites[] = [
                'id' => $row['id'],
                'name' => $name,
                'domain' => $primary_domain,
                'url' => $url
            ];
        }
        
    } catch (Exception $e) {
        error_log("Database error: " . $e->getMessage());
    }
    
    return $sites;
}

// 获取 Docs 目录下的 HTML 文件列表
function getDocsList($docs_path) {
    $docs = [];
    
    if (!is_dir($docs_path)) {
        return $docs;
    }
    
    $files = scandir($docs_path);
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'html') {
            $filepath = $docs_path . '/' . $file;
            $stats = stat($filepath);
            $docs[] = [
                'name' => pathinfo($file, PATHINFO_FILENAME),
                'filename' => $file,
                'mtime' => $stats['mtime']
            ];
        }
    }
    
    // 按修改时间倒序
    usort($docs, function($a, $b) {
        return $b['mtime'] - $a['mtime'];
    });
    
    return $docs;
}

// 主逻辑：优先 API，失败则用数据库
$db_path = '/data/npmplus/database.sqlite';
$sites = getProxyHostsFromApi($config['api_url'], $config['email'], $config['password'], $cookie_jar);

if (empty($sites)) {
    $sites = getProxyHostsFromDb($db_path);
    $data_source = 'database';
} else {
    $data_source = 'api';
}

// 按 ID 升序排列
usort($sites, function($a, $b) {
    return $a['id'] - $b['id'];
});

// 获取文档列表
$docs_path = '/docs';
$docs = getDocsList($docs_path);

// 站点配置
$site_title = '服务导航';
$site_subtitle = '内部服务入口';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site_title) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }
        .container { max-width: 900px; margin: 0 auto; }
        header {
            text-align: center;
            margin-bottom: 50px;
        }
        h1 {
            color: #fff;
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        h2 {
            color: #fff;
            font-size: 1.5rem;
            margin: 40px 0 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .subtitle { color: #8892b0; font-size: 1.1rem; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 20px;
        }
        .card {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 24px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: block;
            position: relative;
        }
        .card:hover {
            transform: translateY(-4px);
            background: rgba(255,255,255,0.1);
            border-color: #64ffda;
            box-shadow: 0 8px 32px rgba(100,255,218,0.15);
        }
        .card-icon {
            font-size: 2rem;
            margin-bottom: 12px;
        }
        .card-title {
            color: #fff;
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .card-domain {
            color: #64ffda;
            font-size: 0.9rem;
            word-break: break-all;
        }
        .card-url {
            color: #8892b0;
            font-size: 0.85rem;
            margin-top: 8px;
            opacity: 0.7;
        }
        .card-id {
            color: #64ffda;
            font-size: 1rem;
            font-weight: 600;
            position: absolute;
            top: 12px;
            right: 16px;
        }
        footer {
            text-align: center;
            margin-top: 60px;
            color: #8892b0;
            font-size: 0.9rem;
        }
        .source {
            color: #64ffda;
            font-size: 0.8rem;
        }
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            justify-content: center;
        }
        .tab {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            color: #8892b0;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .tab:hover, .tab.active {
            background: rgba(100,255,218,0.1);
            border-color: #64ffda;
            color: #64ffda;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1><?= htmlspecialchars($site_title) ?></h1>
            <p class="subtitle"><?= htmlspecialchars($site_subtitle) ?></p>
        </header>
        
        <div class="tabs">
            <div class="tab active" onclick="switchTab('services')">🌐 服务</div>
            <div class="tab" onclick="switchTab('docs')">📄 文档</div>
            <div class="tab" onclick="switchTab('admin')">🔧 管理</div>
        </div>
        
        <div id="services" class="tab-content active">
            <div class="grid">
                <?php if (empty($sites)): ?>
                    <p style="color:#8892b0;text-align:center;grid-column:1/-1;">暂无可用服务</p>
                <?php else: ?>
                    <?php foreach ($sites as $site): ?>
                        <a href="https://<?= htmlspecialchars($site['domain']) ?>" class="card" target="_blank">
                            <div class="card-id">#<?= htmlspecialchars($site['id']) ?></div>
                            <div class="card-icon">🌐</div>
                            <div class="card-title"><?= htmlspecialchars($site['name']) ?></div>
                            <div class="card-domain"><?= htmlspecialchars($site['domain']) ?></div>
                            <div class="card-url"><?= htmlspecialchars($site['url']) ?></div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div id="docs" class="tab-content">
            <div class="grid">
                <?php if (empty($docs)): ?>
                    <p style="color:#8892b0;text-align:center;grid-column:1/-1;">暂无可用文档</p>
                <?php else: ?>
                    <?php foreach ($docs as $doc): ?>
                        <a href="/docs/<?= urlencode($doc['filename']) ?>" class="card" target="_blank">
                            <div class="card-icon">📄</div>
                            <div class="card-title"><?= htmlspecialchars($doc['name']) ?></div>
                            <div class="card-domain"><?= htmlspecialchars($doc['filename']) ?></div>
                            <div class="card-url"><?= date('Y-m-d', $doc['mtime']) ?></div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div id="admin" class="tab-content">
            <div style="max-width:480px;margin:0 auto;">
                <h2 style="margin-top:0;">服务管理</h2>
                <div style="
                    background:rgba(255,255,255,0.05);
                    border:1px solid rgba(255,255,255,0.1);
                    border-radius:12px;
                    padding:28px 24px;
                ">
                    <div style="color:#ccd6f6;margin-bottom:8px;font-size:1.05rem;font-weight:600;">openclaw-gateway</div>
                    <div style="color:#8892b0;font-size:0.9rem;margin-bottom:20px;">宿主机 systemd 服务</div>
                    <button id="restart-btn" onclick="doRestart()" style="
                        background:rgba(100,255,218,0.12);
                        border:1px solid #64ffda;
                        color:#64ffda;
                        padding:10px 24px;
                        border-radius:8px;
                        cursor:pointer;
                        font-size:1rem;
                        transition:all 0.2s;
                    ">重启服务</button>
                    <button id="logs-btn" onclick="toggleLogs()" style="
                        background:rgba(255,255,255,0.05);
                        border:1px solid rgba(255,255,255,0.2);
                        color:#8892b0;
                        padding:10px 24px;
                        border-radius:8px;
                        cursor:pointer;
                        font-size:1rem;
                        margin-left:10px;
                        transition:all 0.2s;
                    ">查看日志</button>
                    <div id="restart-result" style="margin-top:16px;font-size:0.95rem;display:none;"></div>
                </div>
                <div id="log-panel" style="display:none;margin-top:16px;">
                    <div style="
                        display:flex;align-items:center;justify-content:space-between;
                        margin-bottom:8px;
                    ">
                        <span style="color:#8892b0;font-size:0.85rem;">最近 <?= (int)(getenv('LOG_LINES') ?: 50) ?> 行 · 每 3 秒刷新</span>
                        <span id="log-status" style="color:#64ffda;font-size:0.8rem;"></span>
                    </div>
                    <pre id="log-output" style="
                        background:#0d1117;
                        border:1px solid rgba(255,255,255,0.1);
                        border-radius:8px;
                        padding:16px;
                        color:#c9d1d9;
                        font-size:0.78rem;
                        line-height:1.5;
                        overflow-x:auto;
                        overflow-y:auto;
                        max-height:480px;
                        white-space:pre-wrap;
                        word-break:break-all;
                    ">加载中…</pre>
                </div>
                </div>
            </div>
        </div>

        <footer>
            <p>Powered by NPMplus • 数据源: <span class="source"><?= htmlspecialchars($data_source) ?></span> • <span id="local-time"></span></p>
        </footer>
    </div>
    
    <script>
        function updateTime() {
            const now = new Date();
            const options = { timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false };
            document.getElementById('local-time').textContent = now.toLocaleString('zh-CN', options);
        }
        updateTime();
    </script>
    
    <script>
        function switchTab(tabId) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

            document.querySelector('.tab[onclick="switchTab(\'' + tabId + '\')"]').classList.add('active');
            document.getElementById(tabId).classList.add('active');
        }

        async function doRestart() {
            const btn = document.getElementById('restart-btn');
            const result = document.getElementById('restart-result');

            btn.disabled = true;
            btn.textContent = '重启中…';
            result.style.display = 'none';

            try {
                const fd = new FormData();
                fd.append('action', 'restart');
                fd.append('token', '<?= htmlspecialchars(getenv('RESTART_TOKEN') ?: '', ENT_QUOTES) ?>');

                const resp = await fetch(window.location.pathname, { method: 'POST', body: fd });
                const data = await resp.json();

                result.style.display = 'block';
                if (data.ok) {
                    result.style.color = '#64ffda';
                    result.textContent = '✓ ' + (data.msg || '重启成功');
                } else {
                    result.style.color = '#ff6b6b';
                    result.textContent = '✗ ' + (data.msg || '重启失败');
                }
            } catch (e) {
                result.style.display = 'block';
                result.style.color = '#ff6b6b';
                result.textContent = '✗ 请求失败: ' + e.message;
            } finally {
                btn.disabled = false;
                btn.textContent = '重启服务';
            }
        }

        let _logTimer = null;

        function toggleLogs() {
            const panel = document.getElementById('log-panel');
            const btn   = document.getElementById('logs-btn');
            if (panel.style.display === 'none') {
                panel.style.display = 'block';
                btn.style.borderColor = '#64ffda';
                btn.style.color = '#64ffda';
                btn.textContent = '关闭日志';
                fetchLogs();
                _logTimer = setInterval(fetchLogs, 3000);
            } else {
                panel.style.display = 'none';
                btn.style.borderColor = 'rgba(255,255,255,0.2)';
                btn.style.color = '#8892b0';
                btn.textContent = '查看日志';
                clearInterval(_logTimer);
                _logTimer = null;
            }
        }

        async function fetchLogs() {
            const status = document.getElementById('log-status');
            const output = document.getElementById('log-output');
            try {
                const fd = new FormData();
                fd.append('action', 'logs');
                fd.append('token', '<?= htmlspecialchars(getenv('RESTART_TOKEN') ?: '', ENT_QUOTES) ?>');
                const resp = await fetch(window.location.pathname, { method: 'POST', body: fd });
                const data = await resp.json();
                if (data.ok && Array.isArray(data.lines)) {
                    output.textContent = data.lines.join('\n');
                    output.scrollTop = output.scrollHeight;
                    const now = new Date().toLocaleTimeString('zh-CN', {timeZone:'Asia/Shanghai'});
                    status.textContent = '已更新 ' + now;
                } else {
                    output.textContent = data.msg || '获取日志失败';
                }
            } catch (e) {
                output.textContent = '请求失败: ' + e.message;
            }
        }
    </script>
</body>
</html>
