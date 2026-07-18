-- ========== 1. 用户表 ==========
-- SQLite 版本(由 PostgreSQL 迁移脚本 001 转写)
-- 说明:存储管理员/用户账号信息,密码使用 bcrypt 哈希。
-- ★ 注意:无 is_first_login 字段(改为通过安装向导初始化)。
-- 依赖:000_create_system_settings.sql
--
-- 类型映射说明:
--   BIGSERIAL    → INTEGER PRIMARY KEY AUTOINCREMENT
--   JSONB        → TEXT + CHECK(json_valid(...))
--   SMALLINT     → INTEGER
--   TIMESTAMPTZ  → TEXT
--   GIN 索引移除(SQLite 不支持),改用普通索引

CREATE TABLE IF NOT EXISTS users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    username        VARCHAR(64) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,                    -- bcrypt,由安装向导首次创建
    email           VARCHAR(128),
    display_name    VARCHAR(64),
    avatar_url      VARCHAR(255),
    -- preferences 存 JSON 文本(主题、语言、首页布局 ID 等)
    preferences     TEXT NOT NULL DEFAULT '{}' CHECK (json_valid(preferences)),
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    locked_until    TEXT,
    last_login_at   TEXT,
    last_login_ip   VARCHAR(45),
    created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    updated_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- 偏好字段索引(SQLite 不支持 GIN,使用普通索引;无法索引 JSON 内部键)
CREATE INDEX IF NOT EXISTS idx_users_preferences ON users(preferences);

-- 触发器:users.updated_at 自动更新
DROP TRIGGER IF EXISTS trg_users_updated;
CREATE TRIGGER trg_users_updated
    AFTER UPDATE ON users
    FOR EACH ROW
    WHEN NEW.updated_at IS OLD.updated_at
BEGIN
    UPDATE users SET updated_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now')
    WHERE id = NEW.id;
END;
