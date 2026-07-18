-- ========== 1. 用户表 ==========
-- 说明:存储管理员/用户账号信息,密码使用 bcrypt 哈希。
-- ★ 注意:无 is_first_login 字段(改为通过安装向导初始化)。
-- 依赖:000_create_system_settings.sql(set_updated_at 函数)

CREATE TABLE IF NOT EXISTS users (
    id              BIGSERIAL PRIMARY KEY,
    username        VARCHAR(64) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,                    -- bcrypt,由安装向导首次创建
    email           VARCHAR(128),
    display_name    VARCHAR(64),
    avatar_url      VARCHAR(255),
    -- JSONB:用户偏好(主题、语言、首页布局 ID 等)
    preferences     JSONB NOT NULL DEFAULT '{}'::jsonb,
    failed_attempts SMALLINT NOT NULL DEFAULT 0,
    locked_until    TIMESTAMPTZ,
    last_login_at   TIMESTAMPTZ,
    last_login_ip   VARCHAR(45),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 偏好字段常用键 GIN 索引
CREATE INDEX IF NOT EXISTS idx_users_preferences_gin
    ON users USING GIN (preferences jsonb_path_ops);

-- 触发器:users.updated_at 自动更新
DROP TRIGGER IF EXISTS trg_users_updated ON users;
CREATE TRIGGER trg_users_updated
    BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
