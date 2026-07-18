# Docker 部署

NYTab 的主推部署方案是基于 `docker compose` 的三容器组合:

- `nytab-db` —— PostgreSQL 16-alpine 数据库
- `nytab-php` —— 自定义构建的 PHP-FPM 8.2 后端(内嵌前端 dist)
- `nytab-web` —— Nginx alpine,负责反代 PHP-FPM 与托管前端 SPA

三个容器通过共享卷协作,只需 `docker compose up -d` 即可启动完整服务。

## 前置条件

| 组件 | 最低版本 |
| --- | --- |
| Docker | 20.0+ |
| Docker Compose | 2.0+(v2 CLI 插件,即 `docker compose` 子命令) |

通过下面命令确认版本:

```bash
docker --version
docker compose version
```

## 1. 克隆仓库

```bash
git clone <repo-url> nytab
cd nytab
```

## 2. (可选)自定义端口与数据库账号

仓库自带的 `docker-compose.yml` 默认占用宿主机 **6725** 端口(映射到容器内 Nginx 的 80),数据库账号为 `nytab / nytab / nytab`。

如需修改,编辑 `docker-compose.yml`:

### 修改对外端口

```yaml
services:
  nytab-web:
    ports:
      - "8080:80"   # 例如改为 8080:80 即可通过 http://localhost:8080 访问
```

### 修改数据库账号

`nytab-db` 与 `nytab-php` 的环境变量需要 **同时** 修改以保持一致:

```yaml
services:
  nytab-db:
    environment:
      POSTGRES_USER: nytab
      POSTGRES_PASSWORD: your_strong_password   # ← 修改这里
      POSTGRES_DB: nytab

  nytab-php:
    environment:
      DB_HOST: nytab-db
      DB_PORT: "5432"
      DB_NAME: nytab
      DB_USER: nytab
      DB_PASSWORD: your_strong_password         # ← 与上面保持一致
```

::: tip 💡 关于数据库主机
`DB_HOST` 必须是 `nytab-db`(Compose 中数据库服务的名字),而不是 `localhost`。
容器之间通过 Docker 自带 DNS 解析服务名。
:::

## 3. 启动服务

```bash
docker compose up -d
```

`-d` 表示后台运行。首次启动会构建 `nytab-php` 镜像(`Dockerfile` 中会执行 `npm run build` 与 `composer install`),耗时 3-10 分钟。

## 4. 验证服务状态

```bash
docker compose ps
```

正常情况下应看到三个容器均为 `Up` 状态,`nytab-db` 列显示 `(healthy)`:

```
NAME       IMAGE               STATUS                    PORTS
nytab-db   postgres:16-alpine  Up 30 seconds (healthy)   5432/tcp
nytab-php  nytab-php           Up 30 seconds             9000/tcp
nytab-web  nginx:alpine        Up 30 seconds             0.0.0.0:6725->80/tcp
```

## 5. 访问安装向导

浏览器打开 `http://<服务器 IP>:<端口>`,系统会自动跳转到 `/setup` 安装向导。

向导中数据库信息保持默认即可(`nytab-db / 5432 / nytab / nytab / nytab`),详见 [快速开始](/guide/getting-started)。

::: tip ✨ 无需手动建库
安装向导会自动连接 PostgreSQL 的 `postgres` 维护库执行 `CREATE DATABASE`,无需提前手动创建。
:::

## 卷说明

`docker-compose.yml` 声明了 5 个命名卷,确保数据在容器重启/重建后不丢失:

| 卷名 | 挂载点 | 用途 |
| --- | --- | --- |
| `nytab-pgdata` | `nytab-db:/var/lib/postgresql/data` | PostgreSQL 数据持久化 |
| `uploads` | `nytab-php:/var/www/html/uploads` | 用户上传的图标、品牌 Logo、背景图 |
| `storage` | `nytab-php:/var/www/html/storage` | 开发者模式 SQLite 数据库、日志 |
| `app-config` | `nytab-php:/var/www/html/config` | `installed.lock` 与 `.env`(经符号链接持久化),保留 JWT_SECRET 与 DB 凭据 |
| `frontend-dist` | `nytab-php:/srv/frontend-dist` 与 `nytab-web:/usr/share/nginx/html` | 前端 dist 共享卷,PHP 容器构建后同步给 Nginx |

::: warning ⚠️ 不要随便删除 `app-config`
`app-config` 卷里保存了 `JWT_SECRET` 与 `installed.lock`。一旦删除:
- `JWT_SECRET` 丢失 → 所有已签发的 JWT 失效,用户需重新登录
- `installed.lock` 丢失 → 系统认为未安装,会再次跳转安装向导(但不会破坏已有数据)
:::

## 升级流程

```bash
cd nytab
git pull
docker compose build        # 重建 PHP 镜像(包含最新前端 dist)
docker compose up -d        # 滚动重启容器
```

数据库结构变更通过 `backend/migrations/*.sql` 的 `IF NOT EXISTS / OR REPLACE` 保护,可安全重入。
若升级引入了破坏性 schema 变更,会在 Release Notes 中单独说明。

## 备份

### 备份数据库

```bash
docker compose exec nytab-db pg_dump -U nytab nytab > backup_$(date +%F).sql
```

### 备份上传文件

```bash
docker run --rm -v nytab_uploads:/data -v "$PWD":/backup alpine \
  tar czf /backup/uploads_$(date +%F).tar.gz -C /data .
```

### 完整恢复

```bash
# 恢复数据库
cat backup_2025-01-01.sql | docker compose exec -T nytab-db psql -U nytab nytab

# 恢复上传文件
docker run --rm -v nytab_uploads:/data -v "$PWD":/backup alpine \
  tar xzf /backup/uploads_2025-01-01.tar.gz -C /data
```

## 常见问题

### 端口冲突

启动时报 `Bind for 0.0.0.0:6725 failed: port is already allocated`,表示 6725 端口被占用。
解决方法:编辑 `docker-compose.yml`,把 `nytab-web.ports` 改为 `"8080:80"` 等空闲端口,或停掉占用 6725 端口的进程。

### 权限问题

`uploads` / `storage` / `config` 目录在容器内以 `www-data` 用户运行。
若在宿主机直接挂载目录(而非命名卷)出现权限错误,可执行:

```bash
docker compose exec nytab-php chown -R www-data:www-data /var/www/html/uploads /var/www/html/storage /var/www/html/config
```

### 数据库连接失败

安装向导提示「无法连接数据库」时,按以下顺序排查:

1. 确认 `nytab-db` 容器健康:`docker compose ps nytab-db` 应显示 `(healthy)`
2. 确认数据库主机填写的是 `nytab-db`,而非 `localhost` 或 `127.0.0.1`
3. 查看数据库日志:`docker compose logs nytab-db`
4. 进入 PHP 容器手动测试:
   ```bash
   docker compose exec nytab-php php -r \
     "new PDO('pgsql:host=nytab-db;port=5432;dbname=nytab', 'nytab', 'nytab');"
   ```

### 安装后无法登录,JWT 报 401

通常是 `JWT_SECRET` 在容器重建后发生了变化。检查 `app-config` 卷是否被误删:

```bash
docker compose exec nytab-php cat /var/www/html/config/.env | grep JWT_SECRET
```

若 `JWT_SECRET` 为空或与最初安装时不一致,把原值写回该文件后重启 PHP 容器即可。

## 下一步

- [手工部署](/deploy/manual):不适合 Docker 的场景
- [开发者模式](/dev/developer-mode):本地开发免 PostgreSQL
- [插件开发](/dev/plugin-development):扩展工作台
