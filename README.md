# NPMplus 导航页

基于 NPMplus API 的动态服务导航页。

## 功能

- 动态读取 NPMplus 代理主机配置
- 优先使用 API 获取数据，失败时自动回退到 SQLite 数据库
- 显示 Google Drive Docs 目录下的 HTML 文档列表
- Tab 切换：服务 / 文档
- 服务按 ID 升序排列，右上角显示序号

## 配置

### 1. 环境变量

复制 `.env.example`（或创建 `.env`）：
```bash
# NPMplus 数据目录
NPM_DATA_PATH=/srv/docker/npmplus/data

# Google Drive Docs 目录
GDOCS_PATH=/home/falcon/GoogleDrive/Docs
```

### 2. API 凭据

```bash
cp config.php.example config.php
```

编辑 `config.php`，填入你的 NPMplus 账号信息：
```php
$config = [
    'api_url' => 'https://your-npm.example.com',
    'email' => 'your@email.com',
    'password' => 'your-password',
];
```

> ⚠️ 注意：`config.php` 和 `.env` 已加入 `.gitignore`，不会被提交到 Git

## 部署

### Docker Compose

```yaml
services:
  nav:
    build: .
    env_file:
      - .env
    volumes:
      - ./index.php:/var/www/html/index.php
      - ./config.php:/var/www/html/config.php:ro
      - ${NPM_DATA_PATH}:/data:ro
      - ${GDOCS_PATH}:/docs:ro
    ports:
      - 8085:80
    restart: unless-stopped
```

### 构建运行

```bash
docker compose build
docker compose up -d
```

## 访问

- 本地：`http://127.0.0.1:8085`
- 域名：`https://ubt.cellmean.com`

## 项目结构

```
nav-page/
├── .env               # 环境变量（挂载路径）
├── .gitignore         # 忽略敏感文件
├── Dockerfile         # 自定义 Apache 配置
├── compose.yml        # Docker Compose 配置
├── config.php         # API 凭据（不提交）
├── config.php.example # 配置模板
├── index.php         # 主页面
└── README.md
```

## 许可证

MIT
