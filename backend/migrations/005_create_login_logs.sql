-- ========== 5. 登录日志表(防暴力破解辅助) ==========
-- 说明:记录每次登录尝试,用于基于 IP 的防暴力破解统计(同 IP 5 分钟内失败 5 次锁定 15 分钟)。
-- ★ 注意:该表无 updated_at 字段,因此不创建触发器。
-- 依赖:无外键依赖( username 仅作字符串记录,不引用 users 表)

CREATE TABLE IF NOT EXISTS login_logs (
    id          BIGSERIAL PRIMARY KEY,
    username    VARCHAR(64) NOT NULL,
    ip          VARCHAR(45) NOT NULL,
    success     BOOLEAN NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_login_logs_ip_time
    ON login_logs(ip, created_at);

-- 注意:本表仅有 created_at,无 updated_at,无需触发器
