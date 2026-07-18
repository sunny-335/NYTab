-- ========== 0. 系统设置表(安装状态、版本等) ==========
-- 说明:存储系统级键值配置,如 installed 标记、版本号等。
-- 安装完成后由 SetupService 插入 installed 记录。

-- 启用扩展(如需 gen_random_uuid 等)
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- 系统设置表
CREATE TABLE IF NOT EXISTS system_settings (
    key         VARCHAR(64) PRIMARY KEY,
    value       JSONB NOT NULL DEFAULT '{}'::jsonb,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 触发器函数:自动更新 updated_at(全局共享,后续表复用)
CREATE OR REPLACE FUNCTION set_updated_at() RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- 触发器:system_settings.updated_at 自动更新
DROP TRIGGER IF EXISTS trg_settings_updated ON system_settings;
CREATE TRIGGER trg_settings_updated
    BEFORE UPDATE ON system_settings
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- 安装完成后由 SetupService 插入:
-- INSERT INTO system_settings(key, value) VALUES ('installed', '{"version":"1.0.0","installed_at":"..."}');
