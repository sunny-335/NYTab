-- ========== 4. 工具状态表 ==========
-- 说明:为所有工具插件提供按 pluginId 隔离的状态持久化,UNIQUE(user_id, plugin_id) 保证每用户每工具一行。
-- 依赖:001_create_users.sql(users 表 + set_updated_at 函数)

CREATE TABLE IF NOT EXISTS tool_states (
    id          BIGSERIAL PRIMARY KEY,
    user_id     BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    plugin_id   VARCHAR(64) NOT NULL,                        -- 与前端 ToolPlugin.id 对应
    -- JSONB:工具自身状态,完全由插件定义结构
    state       JSONB NOT NULL DEFAULT '{}'::jsonb,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, plugin_id)
);

CREATE INDEX IF NOT EXISTS idx_tool_state_user_plugin
    ON tool_states(user_id, plugin_id);
CREATE INDEX IF NOT EXISTS idx_tool_state_gin
    ON tool_states USING GIN (state jsonb_path_ops);

-- 触发器:tool_states.updated_at 自动更新
DROP TRIGGER IF EXISTS trg_tool_state_updated ON tool_states;
CREATE TRIGGER trg_tool_state_updated
    BEFORE UPDATE ON tool_states
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
