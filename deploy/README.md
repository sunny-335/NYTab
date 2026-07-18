# NYTab 部署文档

本文件描述 NYTab 在 Linux 生产环境（Debian/Ubuntu + Nginx + PHP-FPM + PostgreSQL）下的完整部署流程，对应 `spec.md` 5.4 节部署 Checklist。

> 配置文件均为**占位符模板**：`server_name`、SSL 证书路径、数据库密码等需要按实际环境替换。本文中以 `nytab.example.com` 与 `/var/www/nytab` 为例。

---

## 一、环境要求

| 组件 | 版本 | 用途 | 备注 |
|---|---|---|---|
| PHP | 8.2+ | 后端运行时 | 必须扩展：`pdo_pgsql`、`json`、`openssl`、`mbstring`、`fileinfo`、`curl`（图标抓取） |
| PostgreSQL | 14+（推荐 15+） | 数据存储 | 启用 JSONB + GIN（migration 已包含） |
| Nginx | 1.18+ | 反代 + 静态资源 | 需 `http2`、`ssl` 模块 |
| Node.js | 18+（仅构建时） | 前端构建 | 部署机或 CI 上构建，生产服务器可不安装 |
| Composer | 2.x | 后端依赖管理 | 部署时安装 |

### Debian/Ubuntu 安装示例

```bash
# PHP 8.2 + 必需扩展
sudo apt update
sudo apt install -y php8.2-fpm php8.2-pgsql php8.2-json php8.2-mbstring \
                    php8.2-curl php8.2-xml php8.2-zip php8.2-bcmath php8.2-gd
# Composer
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
# PostgreSQL
sudo apt install -y postgresql postgresql-contrib
# Nginx
sudo apt install -y nginx
# Node.js（仅构建机）
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install -y nodejs
```

---

## 二、目录结构（生产推荐）

```
/var/www/nytab/                    # 项目根（git 仓库）
├── backend/
│   ├── .env                       # ★ 安装向导写入真实密钥,权限 0640
│   ├── .env.example               # 模板,供参考
│   ├── composer.json
│   ├── vendor/                    # composer install 生成
│   ├── config/
│   │   ├── app.php
│   │   ├── database.php
│   │   ├── env.example.php
│   │   └── installed.lock         # ★ 安装完成后生成,nginx deny
│   ├── migrations/                # SQL 迁移脚本
│   ├── public/
│   │   └── index.php              # ★ 后端单入口,nginx fastcgi 指向
│   ├── src/                       # 后端源码
│   └── uploads/
│       └── icons/                 # ★ 用户上传图标,www-data 可写
├── frontend/                      # ★ 前端构建产物软链或拷贝
│   └── dist/
│       ├── index.html
│       ├── assets/                # 带 hash 的 js/css,长期强缓存
│       └── favicon.svg
└── deploy/                        # 部署配置（本目录）
    ├── nginx.conf
    ├── php-fpm.conf
    ├── php.ini
    └── README.md
```

> 实际部署时，可将前端 `dist/` 单独放在 `/var/www/nytab-frontend/`，仓库只放后端代码。本文档为简单起见统一放在 `/var/www/nytab/` 下。

---

## 三、部署步骤

### 1. 克隆代码到 `/var/www/nytab`

```bash
sudo mkdir -p /var/www
sudo chown $USER:$USER /var/www
cd /var/www
git clone <repo-url> nytab
cd nytab
```

### 2. 后端依赖与目录权限

```bash
cd /var/www/nytab/backend

# 安装依赖（不装 dev 包,优化自动加载）
composer install --no-dev --optimize-autoloader

# 复制环境变量模板（也可由安装向导写入,这里仅初始化）
cp .env.example .env

# 权限：.env 必须 0640,运行用户可读
chmod 640 .env

# config 与 uploads/icons 必须 www-data 可写
chown -R www-data:www-data config uploads
chmod -R 770 config uploads/icons
```

### 3. 前端构建

```bash
cd /var/www/nytab
npm ci
npm run build          # 产物输出到 dist/

# 将 dist 拷贝/软链到 nginx root 路径
mkdir -p /var/www/nytab/frontend
cp -r dist /var/www/nytab/frontend/dist
# 或:ln -s /var/www/nytab/dist /var/www/nytab/frontend/dist
chown -R www-data:www-data /var/www/nytab/frontend/dist
```

### 4. 数据库准备

```bash
sudo -u postgres psql <<'SQL'
CREATE USER nytab WITH PASSWORD 'changeme_strong_password';
CREATE DATABASE nytab OWNER nytab ENCODING 'UTF8' LC_COLLATE 'C.UTF-8' LC_CTYPE 'C.UTF-8' TEMPLATE template0;
GRANT ALL PRIVILEGES ON DATABASE nytab TO nytab;
SQL
```

> **空库即可**：表结构由 `/setup` 安装向导执行 `migrations/*.sql` 自动创建。**不要**手动导入 SQL。

### 5. 浏览器访问 `/setup` 完成安装向导

1. 打开 `https://nytab.example.com/setup`
2. 步骤 1：环境检测（PHP 版本、扩展、目录可写性）
3. 步骤 2：数据库连接配置（host/port/name/user/password），点“测试连接”
4. 步骤 3：管理员账号配置（用户名/密码/email），密码需 ≥8 位且包含 3 类字符
5. 步骤 4：点“安装” → 系统执行：
   - 写入 `backend/.env`（DB_* 真实值）
   - 跑全部 `migrations/*.sql`
   - 创建管理员账号（bcrypt, cost=12）
   - 写入 `system_settings('installed', ...)`
   - 写入 `backend/config/installed.lock`
6. 安装完成后 `/setup` 自动重定向到 `/login`

### 6. Nginx 配置

```bash
sudo cp deploy/nginx.conf /etc/nginx/sites-available/nytab
sudo ln -sf /etc/nginx/sites-available/nytab /etc/nginx/sites-enabled/nytab

# 编辑替换占位符: server_name、ssl_certificate 路径
sudo vim /etc/nginx/sites-available/nytab

# 测试配置语法
sudo nginx -t

# 重载
sudo systemctl reload nginx
```

### 7. PHP-FPM 配置

```bash
# PHP-FPM 池配置
sudo cp deploy/php-fpm.conf /etc/php/8.2/fpm/pool.d/nytab.conf

# PHP 生产 php.ini 片段
sudo cp deploy/php.ini /etc/php/8.2/fpm/conf.d/99-nytab.ini

# 创建日志目录
sudo mkdir -p /var/log/php
sudo chown www-data:www-data /var/log/php

# 创建 socket 目录（通常已存在）
sudo mkdir -p /run/php
sudo chown www-data:www-data /run/php

# 重启 php-fpm（务必重启,opcache.validate_timestamps=0 时需重启才能加载新代码）
sudo systemctl restart php8.2-fpm
```

### 8. SSL 证书（Let's Encrypt）

```bash
sudo apt install -y certbot python3-certbot-nginx

# 自动修改 nginx 配置并申请证书
sudo certbot --nginx -d nytab.example.com

# 或手动模式（webroot）
sudo certbot certonly --webroot -w /var/www/letsencrypt -d nytab.example.com

# 测试自动续期
sudo certbot renew --dry-run
```

证书路径会自动写入 `/etc/letsencrypt/live/nytab.example.com/`，与 `nginx.conf` 中的占位符一致。

### 9. 防火墙

```bash
# 仅开放 80/443,关闭 5432 外网
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 22/tcp        # SSH（务必先放行,否则会自锁）
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable

# PostgreSQL 仅监听本地
sudo vim /etc/postgresql/15/main/postgresql.conf
#   listen_addresses = 'localhost'
sudo vim /etc/postgresql/15/main/pg_hba.conf
#   host    nytab   nytab   127.0.0.1/32   md5
#   host    nytab   nytab   ::1/128        md5
sudo systemctl restart postgresql
```

---

## 四、安全 Checklist（spec 5.4 节）

| 项 | 状态 | 说明 |
|---|---|---|
| `.env` 文件权限 0640 | ☐ | `chmod 640 backend/.env`，仅 www-data 可读 |
| `installed.lock` 不暴露 | ☐ | nginx.conf 已配置 `location ~ /installed\.lock$ { deny all; }` |
| PostgreSQL 仅监听 127.0.0.1 | ☐ | `postgresql.conf: listen_addresses = 'localhost'` |
| CORS 白名单严格配置 | ☐ | `.env` 的 `CORS_ORIGINS` 仅写生产域名 |
| 防暴力破解机制启用 | ☐ | 后端 `RateLimitMiddleware` + `login_logs` 表，5 分钟失败 5 次锁 15 分钟 |
| JWT secret 至少 32 字符随机 | ☐ | `openssl rand -hex 32` 生成，写入 `.env` 的 `JWT_SECRET` |
| bcrypt cost ≥ 12 | ☐ | `backend/src/Utils/Hasher.php` 中 `PASSWORD_BCRYPT` cost=12 |
| 关闭 PHP `expose_php`、`display_errors` | ☐ | `deploy/php.ini` 与 `deploy/php-fpm.conf` 均已设置 |
| 启用 HTTPS + HSTS | ☐ | nginx.conf 已配置 HSTS `max-age=63072000; includeSubDomains; preload` |
| 上传目录不执行 PHP | ☐ | nginx.conf 中 `/uploads/` 内嵌 `location ~ \.php$ { deny all; }` |
| Nginx 强制 HTTPS 跳转 | ☐ | 80 端口 `return 301 https://$host$request_uri` |
| 安装向导完成后 setup 接口禁用 | ☐ | 后端 `SetupGuardMiddleware` 检测 `installed.lock` 存在即对所有 `/api/setup/*` 返回 409 |
| 数据库迁移由安装向导执行 | ☐ | 不要手动 `psql < migrations/*.sql`，由 `/setup` 流程统一执行 |

---

## 五、备份策略

### 5.1 PostgreSQL 定时备份

```bash
# 创建备份目录
sudo mkdir -p /var/backups/nytab/db
sudo chown postgres:postgres /var/backups/nytab/db

# 添加 cron 任务（每天凌晨 3 点备份,保留 14 天）
sudo -u postgres crontab -e
# 加入:
#   0 3 * * * pg_dump -Fc nytab > /var/backups/nytab/db/nytab-$(date +\%Y\%m\%d).dump
#   0 4 * * * find /var/backups/nytab/db -name "nytab-*.dump" -mtime +14 -delete
```

### 5.2 uploads 目录备份

```bash
sudo mkdir -p /var/backups/nytab/uploads

# 每周日凌晨 4:30 备份 uploads
sudo crontab -e
#   30 4 * * 0 tar czf /var/backups/nytab/uploads/uploads-$(date +\%Y\%m\%d).tar.gz -C /var/www/nytab/backend uploads
#   30 5 * * 0 find /var/backups/nytab/uploads -name "uploads-*.tar.gz" -mtime +30 -delete
```

### 5.3 异地备份（推荐）

将 `/var/backups/nytab/` 通过 `rsync` 或对象存储工具（如 `rclone`、`aws s3 sync`）同步到异地，避免单点故障。

---

## 六、更新流程

```bash
cd /var/www/nytab

# 1. 拉取新代码
git pull origin main

# 2. 后端依赖更新
cd backend
composer install --no-dev --optimize-autoloader

# 3. 前端重新构建
cd /var/www/nytab
npm ci
npm run build
rm -rf /var/www/nytab/frontend/dist
cp -r dist /var/www/nytab/frontend/dist
chown -R www-data:www-data /var/www/nytab/frontend/dist

# 4. 数据库迁移（如有新 migration）
#    本项目通过 /setup 安装向导执行 migration,后续若新增 migration,
#    需通过运维脚本或后续版本的升级接口执行,切勿在已安装系统上重跑 /setup。

# 5. 重启 php-fpm（清除 opcache 缓存）
sudo systemctl restart php8.2-fpm

# 6. 重载 nginx（如 nginx.conf 有更新）
sudo nginx -t && sudo systemctl reload nginx
```

> **滚动发布建议**：在负载均衡后部署多实例时，先 `git pull` 与构建，再逐台 `systemctl restart php8.2-fpm`，避免同时下线。

---

## 七、常见问题

### Q1：访问站点返回 502 Bad Gateway

**原因**：PHP-FPM 未运行，或 socket 路径不匹配。

**排查**：
```bash
sudo systemctl status php8.2-fpm
sudo ls -l /run/php/php8.2-fpm.sock
sudo tail -f /var/log/php/nytab-error.log
```

**修复**：
- 确认 `php-fpm.conf` 中 `listen = /run/php/php8.2-fpm.sock` 与 `nginx.conf` 中 `fastcgi_pass unix:/run/php/php8.2-fpm.sock;` 一致。
- 确认 `listen.owner`/`listen.group` 为 `www-data`，且 nginx 运行用户也是 `www-data`。

### Q2：访问 `/setup` 报 405 Method Not Allowed

**原因**：后端 `SetupGuardMiddleware` 检测到 `installed.lock` 已存在，认为系统已安装，对所有 `/api/setup/*` 接口返回 409（前端可能表现为 405）。也可能 Nginx 没有正确把请求转发给 PHP-FPM。

**排查**：
```bash
ls -l /var/www/nytab/backend/config/installed.lock
curl -i https://nytab.example.com/api/setup/status
```

**修复**：
- 如果确实需要重装（**严禁在生产执行**）：删除 `installed.lock` 并清空数据库，再访问 `/setup`。
- 如果是首次安装出现该错误：检查 nginx `location /api/` 是否正确配置 `fastcgi_pass` 与 `SCRIPT_FILENAME`。

### Q3：浏览器报 CORS 错误（`Access-Control-Allow-Origin` 缺失）

**原因**：后端 `CorsMiddleware` 仅对 `CORS_ORIGINS` 白名单内的 Origin 返回 CORS 头。

**排查**：
```bash
# 检查 .env 中 CORS_ORIGINS 是否包含前端实际域名(含协议)
cat /var/www/nytab/backend/.env | grep CORS_ORIGINS
# 测试预检请求
curl -i -X OPTIONS https://nytab.example.com/api/auth/login \
     -H "Origin: https://nytab.example.com" \
     -H "Access-Control-Request-Method: POST"
```

**修复**：
- 确认 `.env` 中 `CORS_ORIGINS=https://nytab.example.com`（**严格匹配**，含协议、无尾斜杠）。
- 多个域名用逗号分隔：`CORS_ORIGINS=https://a.com,https://b.com`。
- 修改 `.env` 后需重启 php-fpm（`Env.php` 在新进程才会重读）。

### Q4：登录后 token 没有写入 Cookie，跨标签页登录态丢失

**原因**：本项目使用 JWT 通过 `Authorization: Bearer <token>` Header 传递，**不使用 Cookie**。前端登录后需将 access_token / refresh_token 存储在 `localStorage` 或 `sessionStorage`，并在 axios 拦截器中附加到 Header。

**修复**：参考 `src/api/request.ts` 的实现，确保 `Authorization` Header 在每次请求时被附加。这不是 CORS/Cookie 问题，**不要**尝试用 Cookie 传 token（CSRF 风险）。

### Q5：图标上传 413 Request Entity Too Large

**原因**：Nginx `client_max_body_size` 或 PHP `upload_max_filesize` / `post_max_size` 限制过小。

**修复**：
- nginx.conf 已配置 `client_max_body_size 10M`。
- php.ini 已配置 `upload_max_filesize = 5M`、`post_max_size = 8M`。
- 如需更大文件，同步上调三处配置后 `systemctl restart php8.2-fpm && systemctl reload nginx`。

### Q6：发布新代码后浏览器仍是旧版本

**原因**：`opcache.validate_timestamps=0` 导致 PHP 缓存旧字节码；前端 `dist/assets/*` 命中 immutable 缓存。

**修复**：
- 后端：`sudo systemctl restart php8.2-fpm`（部署脚本必须包含此步）。
- 前端：Vite 产物带 hash，新版本文件名变化后浏览器会自动请求新文件；`index.html` 不缓存（nginx.conf 中已配置 `no-cache`），用户刷新即可。

### Q7：权限问题导致图标上传失败

**排查**：
```bash
ls -ld /var/www/nytab/backend/uploads/icons
# 应为 drwxrwx--- www-data www-data
```

**修复**：
```bash
sudo chown -R www-data:www-data /var/www/nytab/backend/uploads
sudo chmod -R 770 /var/www/nytab/backend/uploads
```

---

## 八、配置文件清单

| 文件 | 用途 | 部署目标路径 |
|---|---|---|
| `deploy/nginx.conf` | Nginx 站点配置 | `/etc/nginx/sites-available/nytab` |
| `deploy/php-fpm.conf` | PHP-FPM www 池 | `/etc/php/8.2/fpm/pool.d/nytab.conf` |
| `deploy/php.ini` | PHP 生产配置片段 | `/etc/php/8.2/fpm/conf.d/99-nytab.ini` |
| `backend/.env.example` | 环境变量模板 | 复制为 `backend/.env` |

部署完成后，请逐项核对 **第四节 安全 Checklist**，确保每一项均已落实。
