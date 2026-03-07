# NPMplus 导航页

基于 NPMplus API 的动态服务导航页。

## 功能

- 动态读取 NPMplus 代理主机配置
- 优先使用 API 获取数据，失败时自动回退到数据库
- 显示每个服务的 ID
- 显示数据源（API/Database）

## 配置

1. 复制配置模板：
```bash
cp config.php.example config.php
```

2. 编辑 `config.php`，填入你的 NPMplus 账号信息：
```php
$config = [
    'api_url' => 'https://your-npm.example.com',
    'email' => 'your@email.com',
    'password' => 'your-password',
];
```

## 部署

### Docker Compose

```yaml
version: '3'
services:
  nav-php:
    image: php:apache
    container_name: nav-php
    ports:
      - "8085:80"
    volumes:
      - ./index.php:/var/www/html/index.php
      - /srv/docker/npmplus/data/npmplus:/data/npmplus:ro
    restart: unless-stopped
```

### 构建运行

```bash
docker compose up -d
```

## 访问

- 本地：`http://127.0.0.1:8085`
- 域名：`https://nav.yourdomain.com`

## 许可证

MIT
