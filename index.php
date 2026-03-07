<?php
/**
 * NPMplus 导航页 - 动态读取代理域名
 * 访问: https://nav.ubt.cellmean.com
 * 
 * 数据源：优先使用 NPMplus API，失败则回退到数据库
 */

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
        
        <footer>
            <p>Powered by NPMplus • 数据源: <span class="source"><?= htmlspecialchars($data_source) ?></span> • <?= date('Y-m-d H:i') ?></p>
        </footer>
    </div>
    
    <script>
        function switchTab(tabId) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            document.querySelector('.tab[onclick="switchTab(\'' + tabId + '\')"]').classList.add('active');
            document.getElementById(tabId).classList.add('active');
        }
    </script>
</body>
</html>
