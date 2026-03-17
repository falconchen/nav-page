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

// 通用 webhook 代理函数
function webhook_proxy(string $path, string $post_body = '', array $extra_headers = []): array {
    $token       = getenv('RESTART_TOKEN');
    $webhook_url = getenv('WEBHOOK_URL');
    if (empty($token) || empty($webhook_url)) {
        return ['ok' => false, 'msg' => 'webhook not configured'];
    }
    $url = preg_replace('/\/[^\/]+$/', '/' . ltrim($path, '/'), $webhook_url);
    $headers = array_merge(['X-Token: ' . $token], $extra_headers);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $body      = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);
    if ($curl_err) {
        return ['ok' => false, 'msg' => 'webhook unreachable: ' . $curl_err];
    }
    $result = json_decode($body, true);
    if (!is_array($result)) {
        return ['ok' => false, 'msg' => 'invalid webhook response (HTTP ' . $http_code . ')'];
    }
    return $result;
}

// 备份列表 action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backups') {
    header('Content-Type: application/json');
    $token = getenv('RESTART_TOKEN');
    if (!hash_equals($token ?: '', $_POST['token'] ?? '')) {
        http_response_code(403); echo json_encode(['ok' => false, 'files' => [], 'msg' => 'invalid token']); exit;
    }
    echo json_encode(webhook_proxy('backups'));
    exit;
}

// 还原 action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    header('Content-Type: application/json');
    $token = getenv('RESTART_TOKEN');
    if (!hash_equals($token ?: '', $_POST['token'] ?? '')) {
        http_response_code(403); echo json_encode(['ok' => false, 'msg' => 'invalid token']); exit;
    }
    $filename = $_POST['filename'] ?? '';
    if (empty($filename)) {
        echo json_encode(['ok' => false, 'msg' => 'missing filename']); exit;
    }
    $body = json_encode(['filename' => $filename]);
    echo json_encode(webhook_proxy('restore', $body, ['Content-Type: application/json']));
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
            <div class="tab active" onclick="switchTab('websites')">🌐 网站</div>
            <div class="tab" onclick="switchTab('docs')">📄 文档</div>
            <div class="tab" onclick="switchTab('browser-info')">🌍 信息</div>
            <div class="tab" onclick="switchTab('admin')">🔧 管理</div>
        </div>
        
        <div id="websites" class="tab-content active">
            <div class="grid">
                <?php if (empty($sites)): ?>
                    <p style="color:#8892b0;text-align:center;grid-column:1/-1;">暂无可用服务</p>
                <?php else: ?>
                    <?php foreach ($sites as $site): ?>
                        <a href="https://<?= htmlspecialchars($site['domain']) ?>" class="card" target="_blank">
                            <div class="card-id">#<?= htmlspecialchars($site['id']) ?></div>
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
                            <div class="card-title"><?= htmlspecialchars($doc['name']) ?></div>
                            <div class="card-domain"><?= htmlspecialchars($doc['filename']) ?></div>
                            <div class="card-url"><?= date('Y-m-d', $doc['mtime']) ?></div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div id="browser-info" class="tab-content">
            <div style="max-width:700px;margin:0 auto;">
                <div style="
                    background:rgba(255,255,255,0.05);
                    border:1px solid rgba(255,255,255,0.1);
                    border-radius:12px;
                    padding:28px 24px;
                ">
                    <h3 style="color:#fff;margin:0 0 20px;font-size:1.1rem;">🌍 浏览器与网络信息</h3>
                    
                    <div id="browser-info-loading" style="color:#8892b0;text-align:center;padding:20px;">
                        加载中…
                    </div>
                    
                    <div id="browser-info-content" style="display:none;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                            <div style="background:rgba(0,0,0,0.2);border-radius:8px;padding:16px;">
                                <div style="color:#8892b0;font-size:0.8rem;margin-bottom:6px;">IP 地址</div>
                                <div id="info-ip" style="color:#64ffda;font-size:1.1rem;font-weight:600;">-</div>
                            </div>
                            <div style="background:rgba(0,0,0,0.2);border-radius:8px;padding:16px;">
                                <div style="color:#8892b0;font-size:0.8rem;margin-bottom:6px;">IP 归属地</div>
                                <div id="info-location" style="color:#fff;font-size:1rem;">-</div>
                            </div>
                            <div style="background:rgba(0,0,0,0.2);border-radius:8px;padding:16px;grid-column:1/-1;">
                                <div style="color:#8892b0;font-size:0.8rem;margin-bottom:6px;">User Agent</div>
                                <div id="info-ua" style="color:#ccd6f6;font-size:0.85rem;word-break:break-all;line-height:1.5;">-</div>
                            </div>
                            <div style="background:rgba(0,0,0,0.2);border-radius:8px;padding:16px;">
                                <div style="color:#8892b0;font-size:0.8rem;margin-bottom:6px;">浏览器</div>
                                <div id="info-browser" style="color:#fff;font-size:1rem;">-</div>
                            </div>
                            <div style="background:rgba(0,0,0,0.2);border-radius:8px;padding:16px;">
                                <div style="color:#8892b0;font-size:0.8rem;margin-bottom:6px;">操作系统</div>
                                <div id="info-os" style="color:#fff;font-size:1rem;">-</div>
                            </div>
                            <div style="background:rgba(0,0,0,0.2);border-radius:8px;padding:16px;">
                                <div style="color:#8892b0;font-size:0.8rem;margin-bottom:6px;">屏幕分辨率</div>
                                <div id="info-screen" style="color:#fff;font-size:1rem;">-</div>
                            </div>
                            <div style="background:rgba(0,0,0,0.2);border-radius:8px;padding:16px;">
                                <div style="color:#8892b0;font-size:0.8rem;margin-bottom:6px;">语言</div>
                                <div id="info-lang" style="color:#fff;font-size:1rem;">-</div>
                            </div>
                        </div>
                        <div style="margin-top:20px;padding-top:16px;border-top:1px solid rgba(255,255,255,0.08);">
                            <div style="color:#8892b0;font-size:0.8rem;margin-bottom:6px;">完整网络信息</div>
                            <pre id="info-network" style="
                                background:#0d1117;
                                border:1px solid rgba(255,255,255,0.1);
                                border-radius:8px;
                                padding:12px;
                                color:#c9d1d9;
                                font-size:0.75rem;
                                line-height:1.4;
                                overflow-x:auto;
                                margin:0;
                                max-height:150px;
                            ">-</pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="admin" class="tab-content">
            <div style="max-width:600px;margin:0 auto;">
                <h2 style="margin-top:0;">服务管理</h2>
                <div style="
                    background:rgba(255,255,255,0.05);
                    border:1px solid rgba(255,255,255,0.1);
                    border-radius:12px;
                    padding:28px 24px;
                ">
                    <div style="color:#ccd6f6;margin-bottom:4px;font-size:1.05rem;font-weight:600;">openclaw-gateway</div>
                    <div style="color:#8892b0;font-size:0.9rem;margin-bottom:20px;">宿主机 systemd 服务</div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <button id="restart-btn" onclick="doRestart()" style="
                            background:rgba(100,255,218,0.12);
                            border:1px solid #64ffda;
                            color:#64ffda;
                            padding:10px 20px;
                            border-radius:8px;
                            cursor:pointer;
                            font-size:0.95rem;
                            transition:all 0.2s;
                        ">重启服务</button>
                        <button id="logs-btn" onclick="toggleLogs()" style="
                            background:rgba(255,255,255,0.05);
                            border:1px solid rgba(255,255,255,0.2);
                            color:#8892b0;
                            padding:10px 20px;
                            border-radius:8px;
                            cursor:pointer;
                            font-size:0.95rem;
                            transition:all 0.2s;
                        ">查看日志</button>
                        <button id="backups-btn" onclick="toggleBackups()" style="
                            background:rgba(255,255,255,0.05);
                            border:1px solid rgba(255,255,255,0.2);
                            color:#8892b0;
                            padding:10px 20px;
                            border-radius:8px;
                            cursor:pointer;
                            font-size:0.95rem;
                            transition:all 0.2s;
                        ">配置备份还原</button>
                    </div>
                    <div id="restart-result" style="margin-top:16px;font-size:0.95rem;display:none;"></div>

                    <div id="log-panel" style="display:none;margin-top:20px;border-top:1px solid rgba(255,255,255,0.08);padding-top:16px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
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
                            max-height:400px;
                            white-space:pre-wrap;
                            word-break:break-all;
                            margin:0;
                        ">加载中…</pre>
                    </div>

                    <div id="backup-panel" style="display:none;margin-top:20px;border-top:1px solid rgba(255,255,255,0.08);padding-top:16px;">
                        <div style="color:#8892b0;font-size:0.85rem;margin-bottom:12px;">选择备份文件还原后自动重启，还原前当前配置自动保存为 .bak</div>
                        <div id="backup-list"><span style="color:#8892b0;font-size:0.9rem;">加载中…</span></div>
                        <div id="restore-result" style="margin-top:12px;font-size:0.95rem;display:none;"></div>
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
            
            // 更新 URL hash
            history.replaceState(null, '', '#' + tabId);
            
            if (tabId === 'browser-info' && document.getElementById('browser-info-content').style.display === 'none') {
                loadBrowserInfo();
            }
        }
        
        // 页面加载时读取 hash 切换 Tab
        (function() {
            const hash = window.location.hash.slice(1);
            const validTabs = ['websites', 'docs', 'browser-info', 'admin'];
            if (validTabs.includes(hash)) {
                // 延迟一下确保 DOM 渲染完成
                setTimeout(() => switchTab(hash), 0);
            }
        })();
        
        let _browserInfoLoaded = false;
        
        async function loadBrowserInfo() {
            if (_browserInfoLoaded) return;
            _browserInfoLoaded = true;
            
            const loading = document.getElementById('browser-info-loading');
            const content = document.getElementById('browser-info-content');
            
            try {
                // 获取 IP 信息
                const ipResp = await fetch('https://ipapi.co/json/');
                const ipData = await ipResp.json();
                
                document.getElementById('info-ip').textContent = ipData.ip || '未知';
                
                let location = '-';
                if (ipData.city) location += ipData.city + ' ';
                if (ipData.region) location += ipData.region + ' ';
                if (ipData.country_name) location += ipData.country_name;
                document.getElementById('info-location').textContent = location === '- - -' ? '-' : location.trim();
                
                // UA 信息
                const ua = navigator.userAgent;
                document.getElementById('info-ua').textContent = ua;
                
                // 解析浏览器
                let browser = '未知';
                if (ua.includes('Firefox')) browser = 'Firefox';
                else if (ua.includes('Edg/')) browser = 'Microsoft Edge';
                else if (ua.includes('Chrome') && !ua.includes('Edg')) browser = 'Chrome';
                else if (ua.includes('Safari') && !ua.includes('Chrome')) browser = 'Safari';
                else if (ua.includes('OPR') || ua.includes('Opera')) browser = 'Opera';
                document.getElementById('info-browser').textContent = browser;
                
                // 解析操作系统
                let os = '未知';
                if (ua.includes('Windows')) os = 'Windows';
                else if (ua.includes('iPhone') || ua.includes('iPad')) os = 'iOS';
                else if (ua.includes('Mac')) os = 'macOS';
                else if (ua.includes('Linux')) os = 'Linux';
                else if (ua.includes('Android')) os = 'Android';
                document.getElementById('info-os').textContent = os;
                
                // 屏幕分辨率
                document.getElementById('info-screen').textContent = screen.width + ' × ' + screen.height;
                
                // 语言
                document.getElementById('info-lang').textContent = (navigator.language || navigator.userLanguage || '-');
                
                // 网络信息
                let netInfo = 'Connection: ';
                if (navigator.connection) {
                    const c = navigator.connection;
                    netInfo += 'effectiveType=' + (c.effectiveType || '-');
                    netInfo += ', downlink=' + (c.downlink || '-') + 'Mbps';
                    netInfo += ', rtt=' + (c.rtt || '-') + 'ms';
                    netInfo += ', saveData=' + (c.saveData ? 'on' : 'off');
                } else {
                    netInfo += '不支持';
                }
                netInfo += '\nOnline: ' + navigator.onLine;
                netInfo += '\nCookieEnabled: ' + navigator.cookieEnabled;
                netInfo += '\nDoNotTrack: ' + (navigator.doNotTrack || '-');
                document.getElementById('info-network').textContent = netInfo;
                
                loading.style.display = 'none';
                content.style.display = 'block';
                
            } catch (e) {
                loading.textContent = '加载失败: ' + e.message;
            }
        }

        async function doRestart() {
            if (!confirm('确认重启 openclaw-gateway 服务？')) return;
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

        function toggleBackups() {
            const panel = document.getElementById('backup-panel');
            const btn   = document.getElementById('backups-btn');
            if (panel.style.display === 'none') {
                panel.style.display = 'block';
                btn.style.borderColor = '#ffb800';
                btn.style.color = '#ffb800';
                btn.textContent = '关闭备份列表';
                loadBackups();
            } else {
                panel.style.display = 'none';
                btn.style.borderColor = 'rgba(255,255,255,0.2)';
                btn.style.color = '#8892b0';
                btn.textContent = '配置备份还原';
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

        async function loadBackups() {
            const list = document.getElementById('backup-list');
            list.innerHTML = '<span style="color:#8892b0;font-size:0.9rem;">加载中…</span>';
            try {
                const fd = new FormData();
                fd.append('action', 'backups');
                fd.append('token', '<?= htmlspecialchars(getenv('RESTART_TOKEN') ?: '', ENT_QUOTES) ?>');
                const resp = await fetch(window.location.pathname, { method: 'POST', body: fd });
                const data = await resp.json();
                if (!data.ok || !Array.isArray(data.files)) {
                    list.innerHTML = '<span style="color:#ff6b6b;">' + (data.msg || '获取失败') + '</span>';
                    return;
                }
                if (data.files.length === 0) {
                    list.innerHTML = '<span style="color:#8892b0;font-size:0.9rem;">无备份文件</span>';
                    return;
                }
                list.innerHTML = '';
                data.files.forEach(f => {
                    const dt = new Date(f.mtime * 1000).toLocaleString('zh-CN', {timeZone:'Asia/Shanghai'});
                    const kb = (f.size / 1024).toFixed(1) + ' KB';
                    const row = document.createElement('div');
                    row.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.06);gap:12px;';
                    row.innerHTML = `
                        <div style="flex:1;min-width:0;">
                            <div style="color:#ccd6f6;font-size:0.88rem;word-break:break-all;">${f.name}</div>
                            <div style="color:#8892b0;font-size:0.78rem;margin-top:2px;">${dt} &nbsp;·&nbsp; ${kb}</div>
                        </div>
                        <button onclick="doRestore('${f.name.replace(/'/g, "\\'")}')" style="
                            flex-shrink:0;
                            background:rgba(255,184,0,0.1);
                            border:1px solid #ffb800;
                            color:#ffb800;
                            padding:6px 14px;
                            border-radius:6px;
                            cursor:pointer;
                            font-size:0.85rem;
                            white-space:nowrap;
                        ">还原并重启</button>`;
                    list.appendChild(row);
                });
            } catch (e) {
                list.innerHTML = '<span style="color:#ff6b6b;">请求失败: ' + e.message + '</span>';
            }
        }

        async function doRestore(filename) {
            if (!confirm(`确认还原配置文件：\n${filename}\n\n当前配置将自动备份为 openclaw.json.bak，然后重启服务。`)) return;
            const result = document.getElementById('restore-result');
            result.style.display = 'none';
            try {
                const fd = new FormData();
                fd.append('action', 'restore');
                fd.append('token', '<?= htmlspecialchars(getenv('RESTART_TOKEN') ?: '', ENT_QUOTES) ?>');
                fd.append('filename', filename);
                const resp = await fetch(window.location.pathname, { method: 'POST', body: fd });
                const data = await resp.json();
                result.style.display = 'block';
                if (data.ok) {
                    result.style.color = '#64ffda';
                    result.textContent = '✓ ' + (data.msg || '还原成功');
                    loadBackups();
                } else {
                    result.style.color = '#ff6b6b';
                    result.textContent = '✗ ' + (data.msg || '还原失败');
                }
            } catch (e) {
                result.style.display = 'block';
                result.style.color = '#ff6b6b';
                result.textContent = '✗ 请求失败: ' + e.message;
            }
        }
    </script>
</body>
</html>
