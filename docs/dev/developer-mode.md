# 开发者模式

NYTab 提供一个「开发者模式」,允许在不配置 PostgreSQL 的情况下,使用本地 SQLite 数据库快速启动后端。适合本地开发、快速演示、PR 调试等场景。

## 什么是开发者模式

开启后,后端会强制切换到 SQLite 数据库(`backend/storage/nytab_dev.sqlite`),并:

1. 跳过 `installed.lock` 安装检查(无需运行安装向导)
2. 自动执行 `backend/migrations/sqlite/*.sql` 初始化 schema
3. 自动 seed 一个 `admin / admin` 默认账号
4. **即使 `backend/.env` 已配置 PostgreSQL,也强制使用 SQLite**

关闭后,后端恢复使用 `backend/.env` 中配置的 PostgreSQL,生产数据不受影响。

## 如何开启

### 方式 1:通过 UI(推荐)

1. 登录 NYTab
2. 进入 **设置 → 开发者选项**
3. 打开「开发者模式」开关

开关打开后,后端会立即:

- 在 `backend/.env` 写入 `APP_DEV_MODE=true`
- 重置数据库连接缓存
- 运行 SQLite 迁移(已初始化则跳过)
- 创建 `admin / admin` 账号(已存在 admin 则跳过)

### 方式 2:手动写入 .env

若 UI 不可用(例如首次启动、未安装),直接编辑 `backend/.env`:

```ini
APP_DEV_MODE=true
```

然后通过 CLI 触发一次初始化:

```bash
cd backend
php -r "require 'vendor/autoload.php'; (new Nytab\Services\DeveloperModeService())->initSqlite();"
```

::: tip 💡 Docker 部署下的手动方式
若使用 Docker 部署,需要进入 PHP 容器执行:

```bash
docker compose exec nytab-php sh -c 'echo "APP_DEV_MODE=true" >> /var/www/html/.env'
docker compose restart nytab-php
```

容器重启后 `Database::isDevMode()` 会读取到新值。
:::

## 开启后的表现

- 后端 PDO 切换到 `sqlite:backend/storage/nytab_dev.sqlite`
- 顶部菜单栏出现橙色提示条:`当前为开发者模式,数据不持久化到生产库`
- 所有 `/api/*` 请求的读写都落到 SQLite 文件
- 已配置的 PostgreSQL 连接被忽略,数据不会被写入或读取

## 关闭开发者模式

### 通过 UI

设置 → 开发者选项 → 关闭开关。后端会:

- 在 `backend/.env` 写入 `APP_DEV_MODE=false`
- 重置数据库连接缓存
- 下一次 `/api/*` 请求落到 PostgreSQL

### 手动

编辑 `backend/.env`,把 `APP_DEV_MODE` 改为 `false` 或直接删除该行,然后重启 PHP-FPM(或 PHP 容器)。

::: tip ✨ 原数据不受影响
关闭开发者模式时,SQLite 文件 `backend/storage/nytab_dev.sqlite` **不会被删除**。
若之后再次开启,会沿用之前的 SQLite 数据。
:::

## 使用场景

- **本地开发**:克隆仓库后无需安装 PostgreSQL,直接开启开发模式即可调试 API
- **快速演示**:给同事/客户演示功能,不用担心污染生产数据
- **PR 调试**:在干净环境下复现 issue,演示完后关闭即可

## 注意事项

::: warning ⚠️ 生产环境请关闭开发者模式
开发者模式下:
- 数据存放在本地 SQLite 文件,无并发写保护,不适合多用户
- 默认 `admin / admin` 账号弱密码,暴露在公网会立即被入侵
- `JWT_SECRET` 仍来自 `.env`(安装时生成),但所有用户/书签数据都在 SQLite 中,与生产数据库脱节

部署到生产前,务必确认 `backend/.env` 中 `APP_DEV_MODE=false` 或该行不存在。
:::

其他注意事项:

- 开发模式下数据 **不持久化到生产数据库**;关闭后 PostgreSQL 中的数据不变
- 关闭后 SQLite 文件保留但不再使用,可手动删除 `backend/storage/nytab_dev.sqlite`
- Docker 部署中,SQLite 文件位于 `storage` 命名卷(`backend/storage/`),容器重建后仍保留

## 相关代码

- `backend/src/Services/DeveloperModeService.php` —— 启用/禁用逻辑与 SQLite 初始化
- `backend/src/Core/Database.php::isDevMode()` —— 判定当前是否处于开发者模式
- `backend/migrations/sqlite/` —— SQLite 专用迁移(与 PostgreSQL 迁移保持表结构一致)
