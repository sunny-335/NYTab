-- ========== 0. 系统设置表(安装状态、版本等) ==========
-- SQLite 版本(由 PostgreSQL 迁移脚本 000 转写)
-- 说明:存储系统级键值配置,如 installed 标记、版本号等。
-- 安装完成后由 SetupService 插入 installed 记录。
--
-- 类型映射说明:
--   JSONB        → TEXT + CHECK(json_valid(...))  (SQLite 无 JSONB,以 JSON 文本存储)
--   TIMESTAMPTZ  → TEXT                            (ISO8601 字符串)
--   触发器函数 set_updated_at() 移除,改用 SQLite 原生 AFTER UPDATE 触发器
--   (WHEN NEW.updated_at IS OLD.updated_at 防止递归;详见下方)

CREATE TABLE IF NOT EXISTS system_settings (
    key         VARCHAR(64) PRIMARY KEY,
    value       TEXT NOT NULL DEFAULT '{}' CHECK (json_valid(value)),
    updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- 触发器:system_settings.updated_at 自动更新
-- SQLite 无共享触发器函数,使用 AFTER UPDATE 触发器;
-- WHEN 子句保证仅当应用层未显式修改 updated_at 时才自动刷新,避免递归。
DROP TRIGGER IF EXISTS trg_settings_updated;
CREATE TRIGGER trg_settings_updated
    AFTER UPDATE ON system_settings
    FOR EACH ROW
    WHEN NEW.updated_at IS OLD.updated_at
BEGIN
    UPDATE system_settings
    SET updated_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now')
    WHERE key = NEW.key;
END;

-- 安装完成后由 SetupService 插入:
-- INSERT INTO system_settings(key, value) VALUES ('installed', '{"version":"1.0.0","installed_at":"..."}');
