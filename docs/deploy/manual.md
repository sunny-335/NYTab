# 手工部署

如果你的服务器已经装好 PHP + PostgreSQL + Nginx,或因安全策略无法使用 Docker,可以按本页步骤手工部署 NYTab。

::: tip 💡 推荐方案
对于绝大多数场景,我们推荐 [Docker 部署](/deploy/docker)。手工部署更适合以下情况:
- 已有自建 PHP + PostgreSQL 环境
- 需要将 NYTab 接入既有 Nginx 反代
- 公司内网禁止运行 Docker
:::

## 前置条件

| 组件 | 最低版本 | 必需 PHP 扩展 |
| --- | --- | --- |
| PHP | 8.1+ | `pdo_pgsql`、`pdo_sqlite`(开发者模式用)、`json`、`curl`(天气代理用) |
| PostgreSQL | 14+ | |
| Node.js | 18+ | 用于构建前端 |
| Nginx | 1.18+ | 或同等能力的 Web 服务器 |
| Composer | 2.5+ | 用于安装 PHP 依赖 |

确认 PHP 扩展:

```bash
php -m | grep -E 'pdo_pgsql|pdo_sqlite|curl|json'
```

## 步骤 1:克隆仓库

```bash
sudo mkdir -p /var/www/nytab
sudo chown $USER:$USER /var/www/nytab
git clone <repo-url> /var/www/nytab
cd /var/www/nytab
```

## 步骤 2:前端构建

```bash
npm ci
npm run build
```

构建产物输出到项目根目录的 `dist/`。

::: tip 💡 想用开发模式?
若想以开发模式运行前端(带 HMR),执行 `npm run dev`,然后在 `backend/.env` 中把 `CORS_ORIGINS` 改为 `http://localhost:5173`。
:::

## 步骤 3:后端依赖

```bash
cd backend
composer install --no-dev --optimize-autoloader
cd ..
```

确保以下目录可被 PHP 进程用户(通常是 `www-data`)读写:

```bash
sudo chown -R www-data:www-data backend/config backend/uploads backend/storage
sudo chmod 0640 backend/.env 2>/dev/null || true
```

## 步骤 4:配置 Nginx

仓库已提供生产级 Nginx 配置示例 `deploy/nginx.conf`,可直接复用:

```bash
sudo cp deploy/nginx.conf /etc/nginx/sites-available/nytab
sudo ln -s /etc/nginx/sites-available/nytab /etc/nginx/sites-enabled/
```

编辑 `/etc/nginx/sites-available/nytab`,把以下占位替换为实际值:

| 占位 | 示例 |
| --- | --- |
| `nytab.example.com` | 你的实际域名 |
| `/etc/letsencrypt/live/.../*.pem` | SSL 证书路径(用 certbot 申请或自签) |
| `/var/www/nytab/frontend/dist` | 前端 `dist/` 的绝对路径(本页步骤默认 `/var/www/nytab/dist`) |
| `/var/www/nytab/backend/public/index.php` | 后端单入口 |
| `/run/php/php8.2-fpm.sock` | PHP-FPM 监听地址(与下一步配置一致) |

::: warning ⚠️ 前端 dist 路径
本仓库前端构建产物在项目根目录的 `dist/`,**不在** `frontend/dist/`。
请把示例配置中的 `root /var/www/nytab/frontend/dist;` 改为 `root /var/www/nytab/dist;`,或把 `dist/` 移动到 `frontend/dist/`。
:::

测试并重载 Nginx:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

## 步骤 5:配置 PHP-FPM

仓库提供 PHP-FPM 池配置示例 `deploy/php-fpm.conf`,按需复制:

```bash
sudo cp deploy/php-fpm.conf /etc/php/8.2/fpm/pool.d/nytab.conf
sudo systemctl restart php8.2-fpm
```

确认 Nginx 配置中的 `fastcgi_pass` 与 PHP-FPM 的 `listen` 一致(默认 `unix:/run/php/php8.2-fpm.sock`)。

## 步骤 6:访问安装向导

浏览器打开 `https://<你的域名>/`,系统会自动跳转到 `/setup` 安装向导。

向导中填入 PostgreSQL 连接信息。**无需提前手动建库**——安装向导会连接 PostgreSQL 的 `postgres` 维护库,自动执行 `CREATE DATABASE`,然后建表、写入 `installed.lock`。

::: tip ✨ 数据库账号需要 CREATEDB 权限
若向导提示「账号无 CREATEDB 权限」,可执行:

```bash
sudo -u postgres psql -c "ALTER ROLE nytab CREATEDB;"
```

或提前手动建库后,在向导中复用:

```bash
sudo -u postgres psql
postgres=# CREATE DATABASE nytab OWNER nytab;
```

:::

完成向导后用管理员账号登录即可开始使用。

## 下一步

- [Docker 部署](/deploy/docker):若后续想迁移到 Docker
- [品牌自定义](/guide/brand-customization):修改昵称 / Title / Logo
- [开发者模式](/dev/developer-mode):本地开发免 PostgreSQL
