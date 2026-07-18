---
layout: home

hero:
  name: NYTab
  text: 多功能标签页与个人效率工作台
  tagline: Vue 3 + PHP 8 + PostgreSQL,可高度自定义的书签管理与工具工作台
  actions:
    - theme: brand
      text: 快速开始
      link: /guide/getting-started
    - theme: alt
      text: Docker 部署
      link: /deploy/docker

features:
  - title: 模块化工作台
    details: CSS Grid 布局 + 拖拽排序,内置 13+ 工具插件(番茄钟、笔记、二维码、Base64、JSON/XML、正则、汇率……),按需启用。
  - title: 开发者模式
    details: 一键切换到本地 SQLite 临时数据库,无需配置 PostgreSQL 即可开发/演示,自动初始化 schema 与 admin 账号。
  - title: 自定义品牌
    details: 程序昵称、浏览器 Title、网站 Logo 均可在「设置 → 个性化」中修改,全站实时生效。
  - title: Docker 一键部署
    details: docker compose up -d 启动 PostgreSQL + PHP-FPM + Nginx 三件套,首次访问自动跳转安装向导并自动建库。
  - title: 自定义背景
    details: 内置 Bing 每日壁纸(自动按设备尺寸切换 PC/移动端),亦支持图片 URL 或本地上传(JPG/PNG/WebP)。
  - title: 智能搜索引擎
    details: 内置 Bing / Google / 百度 / DuckDuckGo / 搜狗 / 360 六大引擎,搜索栏整合书签结果与联想词,键盘可全流程操作。
  - title: 快捷键系统
    details: 支持序列键(如 Z+S)与组合键(如 Ctrl+K),内置冲突检测与系统级黑名单,聚焦输入框时自动暂停。
  - title: 天气小组件
    details: 接入高德 / 和风两家天气 API,30 分钟服务端缓存,支持基于浏览器 Geolocation 的自动定位与城市搜索。
---
