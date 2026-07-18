# =============================================================================
# NYTab Docker 镜像:多阶段构建
# =============================================================================
# Stage 1: 用 node:20-alpine 构建前端 SPA 产物(dist/)
# Stage 2: 用 php:8.2-fpm-alpine 运行后端 + 内嵌前端 dist
#
# 容器内路径约定(与 docker/nginx.conf 保持一致):
#   /var/www/html/                    后端根目录(composer.json 所在层)
#   /var/www/html/public/index.php    后端单入口
#   /var/www/html/public/dist/        前端 SPA 产物(由 Stage 1 复制)
#   /var/www/html/storage/            SQLite(开发模式)/日志,持久化卷
#   /var/www/html/uploads/            用户上传的图标/品牌资源,持久化卷
#   /var/www/html/config/             installed.lock 等配置
#   /srv/frontend-dist/               共享卷挂载点,entrypoint 将 dist 同步到此
#                                     供 nytab-web(nginx)容器读取
# =============================================================================

# -----------------------------------------------------------------------------
# Stage 1: 构建前端
# -----------------------------------------------------------------------------
FROM node:20-alpine AS frontend-builder

WORKDIR /app

# 先复制 package*.json 利用层缓存安装依赖
COPY package*.json ./
RUN npm ci

# 复制源码并构建(.dockerignore 会排除 node_modules / dist 等)
COPY . .
RUN npm run build

# -----------------------------------------------------------------------------
# Stage 2: PHP-FPM 运行时
# -----------------------------------------------------------------------------
FROM php:8.2-fpm-alpine

# 安装 pdo_pgsql(生产 PostgreSQL)、pdo_sqlite(开发者模式)、zip 扩展
RUN apk add --no-cache postgresql-dev sqlite-dev libzip-dev \
    && docker-php-ext-install pdo pdo_pgsql pdo_sqlite zip

# 安装 Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# 复制后端代码(composer.json + src + public + migrations + config)
COPY backend/ /var/www/html/

# 安装后端依赖(生产)。本项目 composer.json 仅声明扩展,无第三方包,
# composer.lock 可能不存在,故失败时降级为 dump-autoload,确保 PSR-4 自动加载器可用。
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    || composer dump-autoload --optimize --no-interaction \
    || true

# 复制前端构建产物到 public/dist(与 vite 默认 outDir 一致)
COPY --from=frontend-builder /app/dist /var/www/html/public/dist

# 预创建可写目录并赋权(uploads/backgrounds 供后续自定义背景功能使用)
RUN mkdir -p storage uploads/backgrounds uploads/branding uploads/icons \
    && chown -R www-data:www-data storage uploads

# 入口点:同步 dist 到共享卷 + 修正权限 + 启动 php-fpm
COPY docker/php-entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
