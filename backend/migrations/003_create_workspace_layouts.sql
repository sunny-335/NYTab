-- ========== 3. 工作台布局表 ==========
-- 说明:每用户一行,存储工具卡片布局数组(layout)与全局工作台设置(settings)。
-- 依赖:001_create_users.sql(users 表 + set_updated_at 函数)

CREATE TABLE IF NOT EXISTS workspace_layouts (
    id          BIGSERIAL PRIMARY KEY,
    user_id     BIGINT NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,  -- 每用户一行
    -- JSONB:工具卡片布局数组
    -- [{ "pluginId": "pomodoro", "x":0,"y":0,"w":2,"h":2,"enabled":true }, ...]
    layout      JSONB NOT NULL DEFAULT '[]'::jsonb,
    -- JSONB:整体工作台偏好(栅格列数、间距、主题色等)
    settings    JSONB NOT NULL DEFAULT '{}'::jsonb,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_workspace_user
    ON workspace_layouts(user_id);
CREATE INDEX IF NOT EXISTS idx_workspace_layout_gin
    ON workspace_layouts USING GIN (layout jsonb_path_ops);

-- 触发器:workspace_layouts.updated_at 自动更新
DROP TRIGGER IF EXISTS trg_workspace_updated ON workspace_layouts;
CREATE TRIGGER trg_workspace_updated
    BEFORE UPDATE ON workspace_layouts
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
