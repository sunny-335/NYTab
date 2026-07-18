# 品牌自定义

NYTab 允许管理员自定义程序昵称、浏览器 Title 与网站 Logo,使产品更贴合你的品牌形象。

## 修改入口

1. 登录 NYTab(需管理员账号)
2. 进入 **设置 → 个性化**

::: tip 💡 仅管理员可修改
品牌设置保存在 `system_settings` 表的 `branding` 键中,只有管理员账号有权限读写。
普通用户只能看到结果,无法修改。
:::

## 可修改项

### 程序昵称

| 项目 | 说明 |
| --- | --- |
| 字段名 | `nickname` |
| 长度限制 | 1 - 32 字符 |
| 默认值 | `NYTab` |
| 显示位置 | 顶部菜单栏、关于页、登录页等 |

### 网站 Title

| 项目 | 说明 |
| --- | --- |
| 字段名 | `title` |
| 长度限制 | 1 - 64 字符 |
| 默认值 | `NYTab` |
| 显示位置 | 浏览器标签页 `<title>` |

### 网站 Logo

| 项目 | 说明 |
| --- | --- |
| 字段名 | `logo` |
| 支持格式 | PNG / JPG / SVG |
| 大小限制 | ≤ 500 KB |
| 默认值 | `/logo.jpg` |
| 存储位置 | 上传后存到 `uploads/branding/`,URL 形如 `/uploads/branding/<uuid>.<ext>` |

::: tip 💡 默认 Logo 不会被删除
上传新 Logo 后,原默认 `/logo.jpg` 文件 **保留** 在原位,只是 branding 配置中的 `logo` 字段被改为新路径。
若想恢复默认,把 `logo` 字段改回 `/logo.jpg` 即可。
:::

## Logo 上传流程

1. 在 **设置 → 个性化** 点击 Logo 区域的「上传」按钮
2. 选择本地 PNG / JPG / SVG 文件(≤ 500 KB)
3. 后端 `BrandingController::uploadLogo()` 接收文件:
   - 校验 MIME 类型与大小
   - 生成 UUID 文件名,移动到 `backend/uploads/branding/`
   - 调用 `BrandingService::updateLogo('/uploads/branding/<uuid>.<ext>')` 更新 `branding.logo` 字段
4. 前端响应式刷新,顶部菜单立即显示新 Logo

::: warning ⚠️ 大文件会被拒绝
若文件 > 500 KB,后端返回 422 错误,前端 toast 提示「logo 无效或过长」。
请先用图片处理工具压缩到 500 KB 以内再上传。
:::

## 修改后全站实时更新

`branding` 配置保存在 `system_settings.branding` JSONB 字段中,所有页面通过 `GET /api/branding` 拉取最新值。

- **顶部菜单**:Vue 响应式 store 在 branding 更新后自动重渲染
- **登录页**:每次访问 `/login` 时拉取最新 branding,确保未登录用户也能看到自定义品牌
- **浏览器标签页 title**:前端在每次 branding 更新后通过 `document.title = branding.title` 实时修改
- **安装向导页**:即使系统未安装,后端 `BrandingService::get()` 也会兜底返回默认值,保证安装页能正常显示 Logo

## 关于版权信息

::: warning ⚠️ 版权信息不可修改
关于页底部的版权信息 `© 暖心向阳335` 由 `BrandingService::COPYRIGHT` 常量硬编码,**不存储在数据库**:
- `BrandingService::get()` 每次返回时都会重新附加这个常量
- `BrandingService::update()` 会 **静默忽略** payload 中的 `copyright` 字段
- 没有任何 API 或数据库操作能修改它

请尊重作者署名,不要尝试通过修改数据库或源码去除版权信息。
:::

## 字段验证规则

后端 `BrandingService::update()` 的验证规则:

| 字段 | 验证 | 失败错误 |
| --- | --- | --- |
| `nickname` | trim 后 1-32 字符 | `nickname 长度需为 1-32 字符` |
| `title` | trim 后 1-64 字符 | `title 长度需为 1-64 字符` |
| `logo` | 1-512 字符的字符串 | `logo 无效或过长(≤512 字符)` |

校验失败时抛 `\InvalidArgumentException(code=42201)`,HTTP 响应 422,前端 toast 显示错误信息。

## 默认值兜底

当系统未安装或数据库不可用时,`BrandingService::get()` 返回以下默认值,保证登录页与安装向导正常显示:

```php
private const DEFAULTS = [
    'nickname' => 'NYTab',
    'title' => 'NYTab',
    'logo' => '/logo.jpg',
];
```

`copyright` 字段始终由 `BrandingService::COPYRIGHT` 常量提供,不参与默认值合并。

## 相关代码

- `backend/src/Services/BrandingService.php` —— 读写 branding 配置、版权常量
- `backend/src/Controllers/BrandingController.php` —— HTTP 入口
- 前端 store 与组件通过 `GET /api/branding` 拉取数据

## 下一步

- [自定义背景](/guide/custom-background):搭配品牌色设置背景图
- [搜索引擎](/guide/search-engines):配置默认搜索体验
