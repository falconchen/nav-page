# NPMplus 导航页

基于 NPMplus API 的动态服务导航页。

## 功能

- 动态读取 NPMplus 代理主机配置
- 优先使用 API 获取数据，失败时自动回退到 SQLite 数据库
- 显示 Google Drive Docs 目录下的 HTML 文档列表
- Tab 切换：服务 / 文档 / 管理
- 服务按 ID 升序排列，右上角显示序号
- 管理 Tab：一键重启 `openclaw-gateway`、实时查看服务日志（3 秒轮询）

## 配置

### 1. 环境变量

复制 `.env.example`（或创建 `.env`）：
```bash
# NPMplus 数据目录
NPM_DATA_PATH=/srv/docker/npmplus/data

# Google Drive Docs 目录
GDOCS_PATH=/home/falcon/GoogleDrive/Docs

# 重启 webhook 配置（管理 Tab 功能）
RESTART_TOKEN=your-secret-token-here
WEBHOOK_URL=http://host.docker.internal:8087/restart
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

### 宿主机 Webhook 服务（管理 Tab 依赖）

管理 Tab 的重启和日志功能依赖宿主机上运行的 webhook 服务：

```bash
# 安装 systemd 单元
sudo cp webhook/openclaw-restart-webhook.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now openclaw-restart-webhook
```

**注意**：`index.php` 以文件方式 bind mount 到容器内。若在容器运行期间修改了 `index.php`（导致 inode 变化），需执行 `docker compose restart` 使容器重新绑定新 inode，否则容器仍会读取旧文件。

## 访问

- 本地：`http://127.0.0.1:8085`
- 域名：`https://ubt.cellmean.com`

## 项目结构

```
nav-page/
├── .env               # 环境变量（挂载路径 + webhook token）
├── .gitignore         # 忽略敏感文件
├── Dockerfile         # 自定义 Apache 配置
├── compose.yml        # Docker Compose 配置
├── config.php         # API 凭据（不提交）
├── config.php.example # 配置模板
├── index.php          # 主页面
├── webhook/
│   ├── restart_webhook.py                  # 宿主机 webhook 服务
│   └── openclaw-restart-webhook.service    # systemd 单元模板
└── README.md
```

## 许可证

MIT
