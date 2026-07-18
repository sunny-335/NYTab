-- ========== 2. 书签分类表(自关联树) + 书签表 ==========
-- SQLite 版本(由 PostgreSQL 迁移脚本 002 转写)
-- 说明:书签分类支持多级树形结构(parent_id 自引用);书签扩展属性存于 extra 字段(JSON 文本)。
-- 依赖:001_create_users.sql(users 表)
--
-- 类型映射说明:
--   BIGSERIAL    → INTEGER PRIMARY KEY AUTOINCREMENT
--   BIGINT FK    → INTEGER REFERENCES ...
--   JSONB        → TEXT + CHECK(json_valid(...))
--   TIMESTAMPTZ  → TEXT
--   GIN 索引移除,改用普通索引

-- ========== 2.1 书签分类表(自关联树) ==========
CREATE TABLE IF NOT EXISTS bookmark_categories (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    parent_id   INTEGER REFERENCES bookmark_categories(id) ON DELETE CASCADE,
    name        VARCHAR(64) NOT NULL,
    icon        VARCHAR(255),
    sort_order  INTEGER NOT NULL DEFAULT 0,
    extra       TEXT NOT NULL DEFAULT '{}' CHECK (json_valid(extra)),   -- 颜色、备注等
    created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

CREATE INDEX IF NOT EXISTS idx_categories_user_parent
    ON bookmark_categories(user_id, parent_id);
CREATE INDEX IF NOT EXISTS idx_categories_extra
    ON bookmark_categories(extra);

-- ========== 2.2 书签表 ==========
CREATE TABLE IF NOT EXISTS bookmarks (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    category_id   INTEGER REFERENCES bookmark_categories(id) ON DELETE SET NULL,
    title         VARCHAR(255) NOT NULL,
    url           VARCHAR(2048) NOT NULL,
    description   VARCHAR(512),
    icon_url      VARCHAR(255),                                  -- 自定义或抓取的 favicon
    sort_order    INTEGER NOT NULL DEFAULT 0,
    -- extra 存 JSON 文本:自定义颜色、标签、备注、openInNewTab 等
    extra         TEXT NOT NULL DEFAULT '{"tags":[],"color":null,"note":"","open_in_new_tab":true}'
                  CHECK (json_valid(extra)),
    created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    updated_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

CREATE INDEX IF NOT EXISTS idx_bookmarks_user_cat
    ON bookmarks(user_id, category_id);
CREATE INDEX IF NOT EXISTS idx_bookmarks_extra
    ON bookmarks(extra);
-- 标签数组查询索引(SQLite 无 GIN,无法索引 JSON 数组元素,使用普通索引兜底)
CREATE INDEX IF NOT EXISTS idx_bookmarks_tags
    ON bookmarks(extra);

-- 触发器:bookmark_categories.updated_at 自动更新
DROP TRIGGER IF EXISTS trg_categories_updated;
CREATE TRIGGER trg_categories_updated
    AFTER UPDATE ON bookmark_categories
    FOR EACH ROW
    WHEN NEW.updated_at IS OLD.updated_at
BEGIN
    UPDATE bookmark_categories SET updated_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now')
    WHERE id = NEW.id;
END;

-- 触发器:bookmarks.updated_at 自动更新
DROP TRIGGER IF EXISTS trg_bookmarks_updated;
CREATE TRIGGER trg_bookmarks_updated
    AFTER UPDATE ON bookmarks
    FOR EACH ROW
    WHEN NEW.updated_at IS OLD.updated_at
BEGIN
    UPDATE bookmarks SET updated_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now')
    WHERE id = NEW.id;
END;
