# NYTab

> 多功能标签页与个人效率工作台

NYTab 是一个基于 Vue 3 + PHP 8 + PostgreSQL 的自托管标签页应用,集书签管理、工具工作台、天气、搜索引擎、快捷键、自定义背景于一体。

## ✨ 功能特性

- **模块化工作台** — CSS Grid 布局 + 拖拽排序,按需启用工具插件
- **书签管理** — 分类、标签、图标自动抓取、智能搜索
- **智能搜索引擎** — 6 大引擎切换(Bing/Google/百度/DuckDuckGo/搜狗/360),书签搜索 + 搜索联想整合
- **自定义快捷键** — 序列键(Z+S)与组合键(Ctrl+K),冲突检测、黑名单、输入时禁用
- **天气小组件** — 高德/和风天气 API,自动定位,30 分钟缓存
- **自定义背景** — Bing 每日壁纸 / API 链接 / 图片上传,设备尺寸自动切换
- **品牌自定义** — 昵称、网站 Title、Logo 全站实时更新
- **开发者模式** — 一键切换 SQLite 临时库,无需 PostgreSQL 即可开发调试
- **Docker 一键部署** — `docker compose up -d` 即可启动完整服务

## 🚀 快速开始

### Docker 部署(推荐)

```bash
git clone https://github.com/sunny-335/NYTab.git
cd NYTab
docker compose up -d
```

访问 `http://localhost` → 跟随安装向导完成配置(数据库默认账号 `nytab/nytab/nytab`,主机填 `nytab-db`)。

### 手动部署

见 [手工部署文档](docs/deploy/manual.md)。

## 📖 文档

完整文档使用 VitePress 构建,位于 `docs/` 目录:

```bash
npm run docs:dev    # 本地预览
npm run docs:build  # 构建静态站点
```

- [快速开始](docs/guide/getting-started.md)
- [Docker 部署](docs/deploy/docker.md)
- [开发者模式](docs/dev/developer-mode.md)
- [插件开发](docs/dev/plugin-development.md)
- [快捷键指南](docs/guide/shortcuts.md)
- [品牌自定义](docs/guide/brand-customization.md)
- [自定义背景](docs/guide/custom-background.md)
- [搜索引擎](docs/guide/search-engines.md)
- [高德天气 API](docs/api-keys/amap-weather.md)
- [和风天气 API](docs/api-keys/qweather.md)

## 🛠 技术栈

| 层 | 技术 |
|---|---|
| 前端 | Vue 3 + TypeScript + Vite + Vue DevUI |
| 后端 | PHP 8.2 + Composer(无框架) |
| 数据库 | PostgreSQL 16(生产)/ SQLite(开发模式) |
| 部署 | Docker + Nginx + PHP-FPM |
| 文档 | VitePress |

## 📂 项目结构

```
NYTab/
├── src/                    # Vue 3 前端源码
│   ├── components/         # 通用组件
│   ├── plugins/tools/      # 工具插件(clock/weather/notes...)
│   ├── views/              # 页面
│   └── stores/             # Pinia 状态管理
├── backend/                # PHP 后端
│   ├── src/
│   │   ├── Controllers/    # 控制器
│   │   ├── Services/       # 业务服务
│   │   ├── Core/           # 核心框架(DB/Router/Env)
│   │   └── Migrations/     # 数据库迁移
│   └── public/index.php    # 入口
├── docs/                   # VitePress 文档
├── docker/                 # Docker 配置
├── deploy/                 # 手动部署配置
├── Dockerfile              # 多阶段构建
└── docker-compose.yml      # 容器编排
```

## 📄 版权

© 暖心向阳335
