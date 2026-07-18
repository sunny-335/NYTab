-- ========== 3. 工作台布局表 ==========
-- SQLite 版本(由 PostgreSQL 迁移脚本 003 转写)
-- 说明:每用户一行,存储工具卡片布局数组(layout)与全局工作台设置(settings)。
-- 依赖:001_create_users.sql(users 表)
--
-- 类型映射说明:
--   BIGSERIAL    → INTEGER PRIMARY KEY AUTOINCREMENT
--   JSONB        → TEXT + CHECK(json_valid(...))
--   TIMESTAMPTZ  → TEXT
--   GIN 索引移除,改用普通索引

CREATE TABLE IF NOT EXISTS workspace_layouts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,  -- 每用户一行
    -- layout 存 JSON 数组:[{ "pluginId": "pomodoro", "x":0,"y":0,"w":2,"h":2,"enabled":true }, ...]
    layout      TEXT NOT NULL DEFAULT '[]' CHECK (json_valid(layout)),
    -- settings 存 JSON 对象:整体工作台偏好(栅格列数、间距、主题色等)
    settings    TEXT NOT NULL DEFAULT '{}' CHECK (json_valid(settings)),
    updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

CREATE INDEX IF NOT EXISTS idx_workspace_user
    ON workspace_layouts(user_id);
CREATE INDEX IF NOT EXISTS idx_workspace_layout
    ON workspace_layouts(layout);

-- 触发器:workspace_layouts.updated_at 自动更新
DROP TRIGGER IF EXISTS trg_workspace_updated;
CREATE TRIGGER trg_workspace_updated
    AFTER UPDATE ON workspace_layouts
    FOR EACH ROW
    WHEN NEW.updated_at IS OLD.updated_at
BEGIN
    UPDATE workspace_layouts SET updated_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now')
    WHERE id = NEW.id;
END;
