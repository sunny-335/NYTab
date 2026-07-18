# 快速开始

本页以 **Docker 一键部署** 为主推方案,帮助你在 5 分钟内拿到一个可用的 NYTab 实例。
如需手工部署 PHP / Nginx / PostgreSQL,请参考 [手工部署](/deploy/manual)。

## 环境要求

| 组件 | 最低版本 | 备注 |
| --- | --- | --- |
| Docker | 20.0+ | 用于运行 PHP-FPM / Nginx / PostgreSQL 三个容器 |
| Docker Compose | 2.0+ | 仓库根目录已提供 `docker-compose.yml` |
| 浏览器 | Chrome / Edge / Firefox 最新版 | 需支持 ES2020 与 CSS Grid |

::: tip 💡 不想装 Docker?
若你的服务器已自带 PHP 8.1+ 与 PostgreSQL 14+,可跳到 [手工部署](/deploy/manual)。
:::

## 主推:Docker 一键部署

### 1. 克隆仓库

```bash
git clone <repo-url> nytab
cd nytab
```

### 2. 启动服务

```bash
docker compose up -d
```

首次启动会构建 PHP 镜像并拉取 PostgreSQL 16 与 Nginx 镜像,耗时取决于网络。

### 3. 访问安装向导

浏览器打开 [http://localhost:6725](http://localhost:6725),系统会自动跳转到安装向导 `/setup`。

在安装向导中填入数据库信息,**默认值已经预填好**(对应 `docker-compose.yml` 中的环境变量):

| 字段 | 默认值 |
| --- | --- |
| 数据库主机 | `nytab-db` |
| 数据库端口 | `5432` |
| 数据库名 | `nytab` |
| 数据库用户 | `nytab` |
| 数据库密码 | `nytab` |

::: warning ⚠️ 生产请修改默认账号
`nytab / nytab / nytab` 仅适用于内网/演示。生产环境请编辑 `docker-compose.yml` 中 `nytab-db` 与 `nytab-php` 的 `environment` 后再 `docker compose up -d`。
:::

### 4. 创建管理员账号

在向导第二步设置管理员用户名与密码。密码强度要求:≥ 8 字符,且包含大小写字母、数字、特殊字符中的至少 3 类。

### 5. 完成

提交后系统会自动:

1. 连接 PostgreSQL 的 `postgres` 维护库,**自动 CREATE DATABASE**(无需手动建库)
2. 写入 `backend/.env`(包含 `DB_*` 与随机生成的 `JWT_SECRET`)
3. 执行 `backend/migrations/*.sql` 建表
4. 创建管理员账号
5. 写入 `backend/config/installed.lock` 标记安装完成

随后页面会跳转到登录页,用刚才创建的管理员账号登录即可使用。

## 首次登录后的建议配置

登录成功后,建议依次前往「设置」完成以下个性化:

1. **品牌自定义**([文档](/guide/brand-customization)):修改昵称、Title、Logo
2. **自定义背景**([文档](/guide/custom-background)):选择 Bing 壁纸 / API 链接 / 上传图片
3. **搜索引擎**([文档](/guide/search-engines)):选择引擎记忆策略与搜索模式
4. **快捷键**([文档](/guide/shortcuts)):按需新增自定义快捷键
5. **天气**([高德密钥](/api-keys/amap-weather) / [和风密钥](/api-keys/qweather)):申请 API Key 后即可在时钟插件中显示天气

## 下一步

- [Docker 部署详解](/deploy/docker):端口、卷、升级、备份等运维细节
- [开发者模式](/dev/developer-mode):无需 PostgreSQL,本地一秒启动
- [插件开发](/dev/plugin-development):为工作台添加你自己的工具
