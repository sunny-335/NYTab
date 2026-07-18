#!/bin/sh
# =============================================================================
# NYTab PHP 容器入口点
# =============================================================================
# 职责:
#   1. 预创建并赋权可写目录(storage / uploads/* / config)
#   2. 将 .env 通过符号链接指向持久化卷(config/.env),
#      使安装向导写入的 JWT_SECRET / DB 凭据在容器重启后仍然保留
#   3. 将内嵌的前端 dist 同步到共享卷 /srv/frontend-dist,
#      供 nytab-web(nginx)容器在 /usr/share/nginx/html 读取
#   4. 启动 PHP-FPM(exec "$@" 透传 CMD)
# =============================================================================
set -e

APP_DIR=/var/www/html
CONFIG_DIR="${APP_DIR}/config"
DIST_SRC="${APP_DIR}/public/dist"
DIST_DST=/srv/frontend-dist

# 1. 确保证书/上传/配置目录存在且可写
mkdir -p "${APP_DIR}/storage" \
         "${APP_DIR}/uploads/backgrounds" \
         "${APP_DIR}/uploads/branding" \
         "${APP_DIR}/uploads/icons" \
         "${CONFIG_DIR}"
chown -R www-data:www-data "${APP_DIR}/storage" "${APP_DIR}/uploads" "${CONFIG_DIR}"

# 2. 将 .env 持久化到 config 卷:建立符号链接 APP_DIR/.env -> config/.env
#    - 首次启动:两处都不存在,建立空链接;安装向导 file_put_contents 时
#      PHP 会跟随符号链接创建 config/.env 实体文件。
#    - 重启后:config/.env 已在持久化卷中,符号链接让 PHP 直接读取到旧值,
#      避免 JWT_SECRET 丢失导致全员掉线、避免 installed.lock 丢失触发重装。
if [ ! -e "${APP_DIR}/.env" ] && [ ! -L "${APP_DIR}/.env" ]; then
    ln -s "${CONFIG_DIR}/.env" "${APP_DIR}/.env"
    echo "[entrypoint] symlinked ${APP_DIR}/.env -> ${CONFIG_DIR}/.env"
fi

# 3. 同步前端 dist 到共享卷(供 nginx 容器读取)
#    首次启动时共享卷为空,直接拷入;后续启动(镜像已更新)时,
#    覆盖已有文件以保证 index.html 指向最新 hash 的 assets。
if [ -d "${DIST_SRC}" ]; then
    mkdir -p "${DIST_DST}"
    # cp -a 保留属性;源末尾的 /. 表示拷贝目录内容(含隐藏文件)而非目录本身
    cp -af "${DIST_SRC}/." "${DIST_DST}/"
    echo "[entrypoint] frontend dist synced to ${DIST_DST}"
else
    echo "[entrypoint] WARNING: ${DIST_SRC} not found, nginx will serve empty root" >&2
fi

# 4. 启动 PHP-FPM(透传 CMD 参数,如 php-fpm 或 php-fpm -F)
exec "$@"
