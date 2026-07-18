-- ========== 4. 工具状态表 ==========
-- SQLite 版本(由 PostgreSQL 迁移脚本 004 转写)
-- 说明:为所有工具插件提供按 pluginId 隔离的状态持久化,UNIQUE(user_id, plugin_id) 保证每用户每工具一行。
-- 依赖:001_create_users.sql(users 表)
--
-- 类型映射说明:
--   BIGSERIAL    → INTEGER PRIMARY KEY AUTOINCREMENT
--   JSONB        → TEXT + CHECK(json_valid(...))
--   TIMESTAMPTZ  → TEXT
--   GIN 索引移除,改用普通索引

CREATE TABLE IF NOT EXISTS tool_states (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    plugin_id   VARCHAR(64) NOT NULL,                            -- 与前端 ToolPlugin.id 对应
    -- state 存 JSON 文本:工具自身状态,完全由插件定义结构
    state       TEXT NOT NULL DEFAULT '{}' CHECK (json_valid(state)),
    updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    UNIQUE (user_id, plugin_id)
);

CREATE INDEX IF NOT EXISTS idx_tool_state_user_plugin
    ON tool_states(user_id, plugin_id);
CREATE INDEX IF NOT EXISTS idx_tool_state
    ON tool_states(state);

-- 触发器:tool_states.updated_at 自动更新
DROP TRIGGER IF EXISTS trg_tool_state_updated;
CREATE TRIGGER trg_tool_state_updated
    AFTER UPDATE ON tool_states
    FOR EACH ROW
    WHEN NEW.updated_at IS OLD.updated_at
BEGIN
    UPDATE tool_states SET updated_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now')
    WHERE id = NEW.id;
END;
