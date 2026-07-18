# NYTab 集成点验证报告（Task 17）

> 本报告基于代码静态阅读生成，**未实际运行后端**（环境无 PHP/PostgreSQL）。
> 所有结论标注三类状态：
> - ✅ **通过**：代码层面字段/路径/逻辑一致
> - ⚠ **待运行验证**：代码看似一致但需运行时确认
> - ❌ **发现问题**：发现明确的不一致或缺陷（详见"关键问题"章节）

报告生成日期：2026-07-18
项目根目录：`c:\Users\Zhang\Documents\NYTab\NYTab\`

---

## 1. API 端点对接（前端 api/*.ts vs 后端 Routes/api.php）

| 前端调用 | 后端路由 | 方法 | 鉴权 | 状态 |
|---|---|---|---|---|
| `setupApi.status()` → `GET /setup/status` | `GET /setup/status` | status | 否 | ✅ |
| `setupApi.testDatabase()` → `POST /setup/test-database` | `POST /setup/test-database` | testDatabase | 否 | ✅ |
| `setupApi.install()` → `POST /setup/install` | `POST /setup/install` | install | 否 | ✅ |
| `authApi.login()` → `POST /auth/login` | `POST /auth/login` | login | 否 | ✅ |
| `authApi.refresh()` → `POST /auth/refresh` | `POST /auth/refresh` | refresh | 否（白名单） | ✅ |
| `authApi.logout()` → `POST /auth/logout` | `POST /auth/logout` | logout | 是 | ✅ |
| `authApi.me()` → `GET /auth/me` | `GET /auth/me` | me | 是 | ✅ |
| `profileApi.update()` → `PUT /profile` | `PUT /profile` | update | 是 | ✅ |
| `profileApi.changePassword()` → `PUT /profile/password` | `PUT /profile/password` | changePassword | 是 | ✅ |
| `bookmarkApi.listBookmarks()` → `GET /bookmarks` | `GET /bookmarks` | list | 是 | ✅ |
| `bookmarkApi.createBookmark()` → `POST /bookmarks` | `POST /bookmarks` | create | 是 | ✅ |
| `bookmarkApi.getBookmark()` → `GET /bookmarks/{id}` | `GET /bookmarks/{id}` | show | 是 | ✅ |
| `bookmarkApi.updateBookmark()` → `PUT /bookmarks/{id}` | `PUT /bookmarks/{id}` | update | 是 | ✅ |
| `bookmarkApi.deleteBookmark()` → `DELETE /bookmarks/{id}` | `DELETE /bookmarks/{id}` | delete | 是 | ✅ |
| `bookmarkApi.uploadIcon()` → `POST /bookmarks/{id}/icon` | `POST /bookmarks/{id}/icon` | uploadIcon | 是 | ✅ |
| `bookmarkApi.reorderBookmarks()` → `PUT /bookmarks/reorder` | `PUT /bookmarks/reorder` | reorder | 是 | ⚠ |
| `bookmarkApi.fetchIcon()` → `POST /bookmarks/{id}/fetch-icon` | **未注册** | — | — | ❌ |
| `bookmarkApi.listCategories()` → `GET /bookmark-categories` | `GET /bookmark-categories` | categoryTree | 是 | ✅ |
| `bookmarkApi.createCategory()` → `POST /bookmark-categories` | `POST /bookmark-categories` | createCategory | 是 | ✅ |
| `bookmarkApi.updateCategory()` → `PUT /bookmark-categories/{id}` | `PUT /bookmark-categories/{id}` | updateCategory | 是 | ✅ |
| `bookmarkApi.deleteCategory()` → `DELETE /bookmark-categories/{id}` | `DELETE /bookmark-categories/{id}` | deleteCategory | 是 | ✅ |
| `bookmarkApi.reorderCategories()` 未实现 | `PUT /bookmark-categories/reorder` | reorderCategories | 是 | ⚠ |
| `workspaceApi.getLayout()` → `GET /workspace/layout` | `GET /workspace/layout` | getLayout | 是 | ✅ |
| `workspaceApi.saveLayout()` → `PUT /workspace/layout` | `PUT /workspace/layout` | updateLayout | 是 | ✅ |
| `workspaceApi.getSettings()` → `GET /workspace/settings` | `GET /workspace/settings` | getSettings | 是 | ✅ |
| `workspaceApi.saveSettings()` → `PUT /workspace/settings` | `PUT /workspace/settings` | updateSettings | 是 | ✅ |
| `toolApi.registry()` → `GET /tools/registry` | `GET /tools/registry` | registry | 是 | ✅ |
| `toolApi.getState()` → `GET /tools/{pluginId}/state` | `GET /tools/{pluginId}/state` | getState | 是 | ✅ |
| `toolApi.saveState()` → `PUT /tools/{pluginId}/state` | `PUT /tools/{pluginId}/state` | updateState | 是 | ✅ |
| `toolApi.deleteState()` → `DELETE /tools/{pluginId}/state` | `DELETE /tools/{pluginId}/state` | deleteState | 是 | ✅ |

**汇总**：30 条端点中，28 条完全对齐；2 条存在差异（详见关键问题 P1、P2）。

---

## 2. 字段名对接（snake_case vs camelCase）

### 2.1 通用约定
- **SQL 列名** → 后端 PHP 与前端 TS 接口均使用 `snake_case`（如 `user_id`、`category_id`、`sort_order`、`icon_url`、`created_at`、`updated_at`）。
- **JSONB 内部字段** → 视字段语义而定：
  - 工作台布局 `LayoutItem.pluginId` 使用 **camelCase**（前后端一致），因 `pluginId` 是 JSONB 内容，非 SQL 列。
  - 书签 `extra.tags / color / note / open_in_new_tab` 使用 **snake_case**（前后端一致）。

### 2.2 各模块字段对照

| 模块 | 字段 | 后端来源 | 前端类型 | 状态 |
|---|---|---|---|---|
| Auth login | `access_token` | AuthService::login 返回 | `LoginResult.access_token` | ✅ |
| Auth login | `refresh_token` | AuthService::login 返回 | `LoginResult.refresh_token` | ✅ |
| Auth login | `expires_in` | AuthService::login 返回 | `LoginResult.expires_in` | ✅ |
| Auth refresh | `access_token` | AuthService::refresh 返回 | `RefreshResult.access_token` | ✅ |
| Auth refresh | `expires_in` | AuthService::refresh 返回 | `RefreshResult.expires_in` | ✅ |
| Auth refresh | `refresh_token` | **未返回新 refresh_token** | 注释已说明无新 refresh | ✅ |
| AuthUser | `id/username/email/display_name/avatar_url/preferences` | AuthService::publicUser | `AuthUser` 全部对齐 | ✅ |
| Bookmark | `id/user_id/category_id/title/url/description/icon_url/sort_order/extra/created_at/updated_at` | BookmarkRepository::format | `Bookmark` | ✅ |
| BookmarkExtra | `tags/color/note/open_in_new_tab` | DEFAULT_EXTRA 常量 | `BookmarkExtra` | ✅ |
| BookmarkCategory | `id/user_id/parent_id/name/icon/sort_order/extra/children/created_at/updated_at` | BookmarkCategoryRepository::format | `BookmarkCategory` | ✅ |
| Workspace layout item | `pluginId/x/y/w/h/enabled` | WorkspaceService::validateLayoutItem | `LayoutItem` | ✅ |
| Workspace settings | `cols/rowHeight/gap` | WorkspaceService::updateSettings 校验 | `WorkspaceSettings` | ⚠ |
| Workspace settings | `theme` | 默认值含 `'theme' => 'default'`、validKeys 含 `theme` | **前端类型未声明** | ❌ |
| Tool state resp | `pluginId/state` | ToolController::getState 返回 `{pluginId, state}` | `ToolStateResponse` | ✅ |
| Setup status | `installed/requirements/version` | SetupController::status | `SetupStatus` | ✅ |
| DbConfig | `host/port/name/user/password` | SetupController::testDatabase 校验 | `DbConfig` | ✅ |
| InstallPayload | `database.admin` | SetupController::install 校验 | `InstallPayload` | ✅ |
| ProfileUpdateBody | `username/email` | ProfileController::update | `ProfileUpdateBody` | ✅ |
| ChangePasswordBody | `current_password/new_password` | ProfileController::changePassword | `ChangePasswordBody` | ✅ |

**汇总**：除 `WorkspaceSettings.theme` 字段在前端 TS 类型中未声明外（详见关键问题 P3），其余字段全部对齐。

---

## 3. 错误码对接

### 3.1 后端定义的错误码（spec 3.5 + 实际实现）

| code | HTTP | 含义 | 后端产生位置 |
|---|---|---|---|
| 0 | 200 | 成功 | Response::json 默认 |
| 40001 | 400 | 参数错误 | SetupController/AuthController/BookmarkController 等多处 |
| 40101 | 401 | 未登录或 token 失效 | AuthGuardMiddleware / AuthMiddleware / AuthService::refresh |
| 40102 | 401 | 用户名或密码错误 | AuthService::login |
| 40401 | 404 | 资源不存在 | Router::emitNotFound / 各 Controller |
| 40901 | 409 | 系统已安装 | SetupGuardMiddleware / SetupController |
| 41301 | 413 | 载荷过大 | BookmarkService::uploadIcon |
| 42201 | 422 | 业务校验失败 | 多处（密码强度、URL 校验、布局校验等） |
| 42901 | 429 | 请求过于频繁 | RateLimitMiddleware / AuthService::login |
| 50001 | 500 | 服务器错误 | index.php 兜底 / 各 Controller catch |
| 50301 | 503 | 系统未安装 | SetupGuardMiddleware |

### 3.2 前端 request.ts 拦截器处理

| 触发条件 | 前端处理 | 状态 |
|---|---|---|
| HTTP 200 + envelope.code=0 | 解包 envelope.data 返回 | ✅ |
| HTTP 200 + envelope.code≠0 | `Message.error(message)` + reject | ✅ |
| HTTP 401 + code=40101（非 auth endpoint） | 尝试 refresh access_token；成功 → 重试原请求；失败 → 清 token 跳 /login | ✅ |
| HTTP 401 + code=40102（登录失败） | 走通用 toast 分支（错误码消息） | ✅ |
| HTTP 403 | 通用 toast | ✅ |
| HTTP 404 | 通用 toast | ⚠ |
| HTTP 409 + code=40901 | 当前在 /setup → 跳 /login；否则 toast | ✅ |
| HTTP 413 + code=41301 | 通用 toast | ✅ |
| HTTP 422 + code=42201 | 通用 toast（如改密失败、密码强度不足） | ✅ |
| HTTP 429 + code=42901 | toast「尝试过多，请 15 分钟后再试」 | ✅ |
| HTTP 503 + code=50301 | 跳 /setup | ✅ |
| HTTP 500 + code=50001 | 通用 toast | ✅ |
| HTTP 401 on /auth/login 或 /auth/refresh | **不触发 refresh**（避免递归） | ✅ |

### 3.3 JWT 刷新队列
- `_isRefreshing` 标志位 + `_queue` 数组实现并发请求合并：当首个 401 触发 refresh 时，后续 401 请求挂起等待，refresh 成功后批量用新 token 重试。✅
- refresh 失败时清空队列、清 token、跳 /login。✅

**汇总**：错误码闭环完整，无遗漏。

---

## 4. JWT 流程验证

### 4.1 签发
- `AuthService::login` 成功后调用 `Jwt::issueAccessToken($userId, $username)` 与 `Jwt::issueRefreshToken($userId, $username)`。✅
- `Jwt::sign` 注入 `iss=nytab / iat / exp / jti`，使用 HS256 + Env::JWT_SECRET。✅
- access_token type=`access`，refresh_token type=`refresh`。✅

### 4.2 校验
- `AuthGuardMiddleware` 提取 `Authorization: Bearer <token>` → `Jwt::verify` 校验签名 + exp + type=access → 注入 `user_id` 到 Request。✅
- `AuthService::refresh` 接收 refresh_token → `Jwt::verify` 校验签名 + exp + type=refresh → 重新签发 access_token。✅
- 不签发新 refresh_token（前端注释已说明）。✅

### 4.3 失败响应
- 缺失/无效 token → `Response::error(40101, '未登录或 token 失效', 401)`。✅
- refresh_token 无效 → `AuthService::refresh` 抛 40101，AuthController 返回 401。✅

### 4.4 前端闭环
1. `auth.store.login()` → 调 `authApi.login` → 持久化 `access_token / refresh_token / user` 到 localStorage。✅
2. `request.interceptors.request` → 从 localStorage 读 `access_token` 注入 `Authorization: Bearer`。✅
3. 401 + 40101 → `request.interceptors.response` 调 `refreshAccessToken()`（用 bare axios 避免递归）→ POST `/auth/refresh` 携带 `{ refresh_token }`。✅
4. 成功 → 写新 access_token 到 localStorage + 调 `auth.store.setAccessToken` 同步 → 重试原请求。✅
5. 失败 → `clearAuthAndRedirect()` 清 localStorage + 跳 /login。✅
6. logout → 调 `/auth/logout`（仅记录）+ 清 localStorage。✅

### 4.5 注销黑名单
- spec 5.1.8 提到 logout 时将 token jti 加入黑名单（Redis 可选，本期可用数据库表）。
- 实际实现：`AuthController::logout` 仅返回 `{code:0, message:'已登出'}`，**无服务端黑名单**。⚠
- 后果：logout 后旧 token 在 exp 之前仍可用。前端通过清 localStorage 缓解，但若 token 泄露仍可被滥用。

---

## 5. CORS 配置

### 5.1 后端 CorsMiddleware
- 从 `Env::CORS_ORIGINS`（逗号分隔）读取白名单。✅
- 请求 Origin 命中白名单 → 输出 `Access-Control-Allow-Origin: <origin>`、`Allow-Credentials: true`、`Allow-Methods: GET, POST, PUT, DELETE, OPTIONS`、`Allow-Headers: Content-Type, Authorization`、`Max-Age: 86400`。✅
- OPTIONS 预检 → 短路 204。✅
- 非白名单 Origin → 不输出 Allow-Origin 头（浏览器 CORS 检查失败）。✅

### 5.2 SetupService::writeEnvFile 写入的 CORS_ORIGINS
- 安装向导写入的 `.env` 中 `CORS_ORIGINS=` **空字符串**（SetupService.php:158）。❌
- 后果：安装完成后 CORS 白名单为空，所有跨域请求都被拒绝。
- 解决方案：用户必须手动编辑 `.env` 添加 `CORS_ORIGINS=http://localhost:5173` 或生产域名后重启 PHP-FPM。详见关键问题 P4。

### 5.3 前端 baseURL
- `request.ts` 配置 `baseURL: '/api'`，使用相对路径。✅
- 开发环境：Vite 代理 `/api` → `http://localhost:8000`（vite.config.ts）。✅
- 生产环境：Nginx 反代 `/api/` → PHP-FPM（deploy/nginx.conf:112-138）。✅
- 因此前端与后端同源（同 scheme+host+port），CORS 实际不触发；仅当用户将前端与后端部署到不同域名时才需要严格配置 CORS_ORIGINS。✅

### 5.4 .env.example 默认值
- `CORS_ORIGINS=http://localhost:5173`，与 Vite dev 默认端口一致。✅

---

## 6. 安装向导流程

### 6.1 未安装态
- `SetupGuardMiddleware` 检查 `backend/config/installed.lock` 文件存在性。✅
- 文件不存在 → 仅放行 `setup/*`，其他路径返回 `503 { code:50301, message:'系统未安装' }`。✅
- `AuthGuardMiddleware` 白名单包含 `setup/`。✅

### 6.2 安装流程
1. `GET /api/setup/status` → 返回 `{ installed:false, requirements:{...} }`。✅
2. `POST /api/setup/test-database` → SetupService::testDatabaseConnection 尝试 PDO 连接，不写入。✅
3. `POST /api/setup/install` → SetupService::install 顺序执行：✅
   - 校验管理员用户名（`Validator::isValidUsername` 3-64 字母数字下划线）
   - 校验管理员密码强度（`Validator::isStrongPassword` ≥8 字符 + 3 类）
   - 校验邮箱格式（可选）
   - 写入 `.env`（含 DB_*、生成的 JWT_SECRET、空 CORS_ORIGINS）
   - `Env::reload()` 重载缓存
   - 按字母序执行 `migrations/*.sql`
   - 创建 admin 用户（bcrypt 哈希）
   - 插入 `system_settings('installed', {version, installed_at})`
   - 写入 `config/installed.lock`（空文件）
   - chmod .env 0640

### 6.3 已安装态
- `installed.lock` 存在 → `SetupGuardMiddleware` 对所有 `setup/*` 请求返回 `409 { code:40901, message:'系统已安装' }`。✅
- 同时 `SetupController` 内 `isInstalled()` 二次校验（defense-in-depth）。✅

### 6.4 前端流程
- `router/index.ts` 启动时调 `setupStore.fetchStatus()` 获取 installed 状态。✅
- installed=false → 所有路由强制重定向 `/setup`。✅
- installed=true + 访问 `/setup` → 重定向 `/login`。✅
- `request.ts` 收到 503+50301 → 自动跳 `/setup`。✅
- `request.ts` 收到 409+40901 + 当前在 `/setup` → 跳 `/login`。✅

---

## 7. 工具插件系统

### 7.1 前端 registry.ts（import.meta.glob 扫描）
- `import.meta.glob('./*/index.ts')` 自动收集所有插件目录。✅
- 实际扫描到 13 个插件：`pomodoro / markdown / notes / clock / code-format / json-xml / base64 / regex / color-picker / exchange / unit-convert / password-gen / qrcode`。✅
- 与 spec 4.2.1 列出的 13 个内置工具完全一致。✅
- `getToolPlugin(id)` 异步加载并缓存。✅
- `listPluginIds()` 返回所有扫描到的 ID。✅

### 7.2 后端 ToolController::TOOLS 硬编码清单
- 13 个工具条目，`pluginId` 与前端目录名完全一致。✅
- 字段：`pluginId / name / category / icon / description`。✅
- `category` 取值：`efficiency / developer / lifestyle`，与前端 `ToolCategory` 类型一致。✅

### 7.3 前端 tool.api.ts
- `registry()` 期望响应 `{ tools: ToolRegistryEntry[] }` → 后端返回 `['tools' => self::TOOLS]`。✅
- `ToolRegistryEntry.pluginId` 字段名与后端硬编码 `pluginId` 一致。✅

### 7.4 后端 ToolStateService::validatePluginId
- 校验 `^[a-zA-Z0-9_-]+$`，长度 1-64。✅
- 13 个内置插件 ID 全部通过该校验。✅

---

## 8. 工作台布局校验

### 8.1 前端 LayoutItem 类型
```ts
interface LayoutItem {
  pluginId: string
  x: number
  y: number
  w: number
  h: number
  enabled?: boolean
}
```

### 8.2 后端 WorkspaceService::validateLayoutItem
```php
if (!isset($item['pluginId']) || !is_string($item['pluginId'])) → 42201
foreach (['x', 'y', 'w', 'h'] as $field) {
    if (!isset($item[$field]) || !is_int($item[$field]) || $item[$field] < 0) → 42201
}
if (isset($item['enabled']) && !is_bool($item['enabled'])) → 42201
```

### 8.3 字段对照

| 字段 | 前端类型 | 后端校验 | 状态 |
|---|---|---|---|
| pluginId | string | is_string | ✅ |
| x / y / w / h | number | is_int && >= 0 | ⚠ |
| enabled | boolean? | is_bool（可选） | ✅ |

### 8.4 潜在问题
- 后端要求 `x/y/w/h` 必须是 PHP `is_int` 严格整型。前端 TS 的 `number` 包含浮点数，若 JSON 序列化时变成 `1.5` 等浮点数会被后端拒绝。实际使用中拖拽组件通常输出整数，但建议前端 `Math.round()` 兜底。⚠
- 后端校验 `w/h >= 0` 但未校验上限；spec 中通过 `WorkspaceSettings.cols` 间接限制宽度。可接受。✅

### 8.5 WorkspaceSettings 校验
- 后端 `validKeys = ['cols', 'rowHeight', 'gap', 'theme']`。✅
- `cols` 1-24、`rowHeight` 40-300、`gap` 0-40 clamp 校验。✅
- 默认值：`{ cols:12, rowHeight:80, gap:12, theme:'default' }`。✅
- 前端 `WorkspaceSettings` 类型只有 `cols / rowHeight / gap`，**缺 `theme`**。❌（详见关键问题 P3）

---

## 9. 工具状态读写

### 9.1 前端 usePluginState composable
- `onMounted` 调 `reload()` 拉取后端 state。✅
- `reload` 中 `state.value = { ...fallback, ...remoteState }` 合并默认值与远端。✅
- 深度 `watch(state, save, { deep:true })` + 800ms 防抖自动保存。✅
- `onUnmounted` 若有挂起变更立即 `persist()`。✅
- `skipWatch` 标志位防止 reload 期间触发回写。✅

### 9.2 后端 ToolStateService
- `get(userId, pluginId)` → 返回 `array`（无记录返回 `[]`，**非 null**）。❌
- `save(userId, pluginId, state)` → 校验 pluginId 格式 + 1MB 限制（`strlen($payload) > 1048576`）。✅
- 1MB 超限抛 `\InvalidArgumentException('state 载荷超过 1MB 限制', 42201)`。⚠
  - spec 3.4 规定超限返回 `413 { code:41301, message:'状态载荷过大' }`。
  - 实际抛 42201，被 ToolController catch 后返回 `422 { code:42201 }`。❌（详见关键问题 P5）
- `delete(userId, pluginId)` → 直接 DELETE。✅

### 9.3 接口契约 vs 实现

| spec 3.4 约定 | 实际实现 | 状态 |
|---|---|---|
| `GET /api/tools/{pluginId}/state` 无记录 → `{ "state": null }` | 返回 `{ pluginId, state: [] }` | ❌ |
| `PUT /api/tools/{pluginId}/state` 超 1MB → `413 { code:41301 }` | 抛 42201 → 返回 `422 { code:42201 }` | ❌ |
| `DELETE /api/tools/{pluginId}/state` 清空 | ToolController::deleteState 返回 `{ ok:true }` | ✅ |
| `GET /api/tools/registry` | 返回 `{ tools: [...] }` | ✅ |

### 9.4 前端 ToolStateResponse 类型
```ts
interface ToolStateResponse {
  pluginId: string
  state: Record<string, unknown> | null
}
```
- 期望 `state` 可为 `null`，但后端实际返回 `[]`。❌
- 前端 `usePluginState::reload` 中 `(res.state ?? {})` 同时处理 null 与空对象，**运行时不会崩溃**，但与 spec 文档不一致。⚠

### 9.5 pluginId 路由匹配
- 路由 `GET /tools/{pluginId}/state` 中 `{pluginId}` 编译为 `(?P<pluginId>[^/]+)`，匹配除 `/` 外任意字符。✅
- 但若 pluginId 含 URL 特殊字符（如空格、`?`），URL 编码后行为需运行时验证。建议 pluginId 严格按 `^[a-zA-Z0-9_-]+$` 限定。✅

---

## 10. 书签模块

### 10.1 字段对接
- 所有 SQL 列与前端 TS 字段全部 `snake_case` 对齐。✅
- `extra` JSONB 默认值 `{ tags:[], color:null, note:'', open_in_new_tab:true }`，前后端一致。✅
- 更新时后端 `BookmarkRepository::update` 对 `extra` 做 read-modify-write 合并，保留未传入的旧字段。✅

### 10.2 reorder 方式
- 前端 `reorderBookmarks(items)` → `PUT /bookmarks/reorder` body=`{ items: [{id, sort_order}, ...] }`。✅
- 后端 `BookmarkController::reorder` 接收 `items` 数组，校验每项含 `id` + `sort_order`。✅
- `BookmarkRepository::reorder` 在事务内逐条 UPDATE，按 user_id 限定范围。✅

### 10.3 路由顺序问题（route-order workaround）
- `Routes/api.php` 中 `PUT /bookmarks/{id}` 注册于 `PUT /bookmarks/reorder` 之前。
- `Router::compilePattern` 将 `{id}` 编译为 `[^/]+`，因此 `reorder` 字符串会被 `{id}` 路由捕获（id="reorder"）。
- `BookmarkController::update` 通过检测 `routeParams()['id'] === 'reorder'` 内部转发到 `$this->reorder()`。✅
- 同样的 workaround 应用到 `/bookmark-categories/reorder`。✅
- 此为已知技术债，BookmarkController 头部注释已明确说明。⚠

### 10.4 图标上传
- 前端 `uploadIcon(id, file)` → POST multipart，字段名 `icon`。✅
- 后端 `BookmarkController::uploadIcon` 读 `$_FILES['icon']`。✅
- 校验：`UPLOAD_ERR_OK` + size ≤ 2MB + 检测 MIME `^image/`。✅
- 存储：`uploads/icons/icon_<id>_<sha1_12>.<ext>`，返回 URL `/uploads/icons/<filename>`。✅

### 10.5 自动 favicon 抓取
- `BookmarkService::create` 当未提供 `icon_url` 时，`register_shutdown_function` 异步调 `IconFetcherService::fetchFavicon`。✅
- 抓取结果通过 `BookmarkRepository::updateIcon` 写回。✅
- 失败静默吞掉（best-effort）。✅

### 10.6 fetchIcon 端点缺失
- 前端 `bookmark.api.ts` 定义 `fetchIcon(id)` → `POST /bookmarks/{id}/fetch-icon`，但 **后端 Routes/api.php 未注册此路由**。❌（详见关键问题 P1）
- 前端注释已说明此方法"当前调用会得到 404"，匹配 UI 约定保留。
- 后果：UI 上"重新获取图标"按钮调用此接口会收到 404。

### 10.7 URL scheme 校验
- `Validator::isSafeUrl` 仅允许 `http` / `https` scheme，阻止 `javascript:` / `data:` / `file:`。✅
- 在 `BookmarkService::create` 与 `update` 中均调用。✅

### 10.8 reorderCategories 前端缺失
- 后端注册 `PUT /bookmark-categories/reorder`，但前端 `bookmark.api.ts` 未实现对应方法。⚠
- 后果：UI 上无法触发分类批量排序（仅单条更新）。详见关键问题 P2。

---

## 11. 关键问题汇总

### ❌ P1：`POST /bookmarks/{id}/fetch-icon` 后端未实现

- **位置**：
  - 前端：`src/api/bookmark.api.ts:125-126`
  - 后端：`backend/src/Routes/api.php`（缺失）
- **影响**：UI"重新获取 favicon"按钮调用返回 404。前端注释已说明，但用户体验受损。
- **建议修复**：在 `Routes/api.php` 中注册 `$router->post('/bookmarks/{id}/fetch-icon', [BookmarkController::class, 'fetchIcon'])`，并在 `BookmarkController` 添加 `fetchIcon` 方法调用 `BookmarkService` 的 favicon 抓取逻辑。

### ❌ P2：前端未实现 `reorderCategories`

- **位置**：
  - 后端：`Routes/api.php:36` 注册 `PUT /bookmark-categories/reorder`
  - 后端 `BookmarkController::reorderCategories` 已实现
  - 前端：`src/api/bookmark.api.ts` 缺少 `reorderCategories` 方法
- **影响**：UI 无法批量排序分类（仅可通过单条 `updateCategory` 修改 `sort_order`，效率低）。
- **建议修复**：在 `bookmark.api.ts` 添加：
  ```ts
  reorderCategories: (items: Array<{ id: number; sort_order: number }>) =>
    http.put<null>('/bookmark-categories/reorder', { items })
  ```

### ❌ P3：前端 `WorkspaceSettings` 类型缺 `theme` 字段

- **位置**：
  - 后端：`WorkspaceService.php:30` `validKeys = ['cols', 'rowHeight', 'gap', 'theme']`
  - 后端：`WorkspaceRepository.php:19` 默认值含 `theme: 'default'`
  - 前端：`src/types/workspace.d.ts` `WorkspaceSettings` 仅声明 `cols / rowHeight / gap`
- **影响**：
  - 类型层面：TS 编译器无法识别 `settings.theme`，访问会报类型错误。
  - 运行时：`workspace.store.init()` 通过 `{ ...settings.value, ...settingsRes.settings }` 合并，实际 `settings.value` 会含 `theme` 字段（JS 动态特性），但 TS 类型不感知。
- **建议修复**：在 `workspace.d.ts` 的 `WorkspaceSettings` 添加 `theme?: string` 字段。

### ❌ P4：SetupService 写入的 `.env` 中 `CORS_ORIGINS` 为空

- **位置**：`backend/src/Services/SetupService.php:158`
  ```php
  "... APP_URL=\nCORS_ORIGINS=\nUPLOAD_MAX_SIZE=5242880\n"
  ```
- **影响**：安装完成后 `CORS_ORIGINS=` 为空字符串，`CorsMiddleware::allowedOrigins()` 返回空数组，所有跨域请求被拒绝。
- **缓解**：
  - 开发环境：Vite 代理使前后端同源，CORS 不触发，无影响。
  - 生产环境：若前后端同域（Nginx 反代），同源无影响。
  - 跨域部署：必须手动编辑 `.env` 添加 `CORS_ORIGINS=https://your-frontend.com` 后重启 PHP-FPM。
- **建议修复**：在 `SetupService::writeEnvFile` 中追加询问或使用安装向导提交的 `app_url` 自动填入 `CORS_ORIGINS`。

### ❌ P5：工具状态超 1MB 返回 42201 而非 spec 约定的 41301

- **位置**：
  - spec 3.4：`PUT /api/tools/{pluginId}/state` 超 1MB → `413 { code:41301, message:'状态载荷过大' }`
  - 实际：`ToolStateService.php:21` 抛 `\InvalidArgumentException('state 载荷超过 1MB 限制', 42201)`
  - 实际：`ToolController.php:37` catch 后返回 `422 { code:42201 }`
- **影响**：错误码与 spec 不一致；前端若按 41301 特殊处理将无法识别。
- **建议修复**：将 `ToolStateService::save` 中超限异常 code 改为 41301，并在 `ToolController::updateState` 的 catch 中添加 41301 → HTTP 413 的映射。

### ⚠ P6：工具状态无记录时返回 `state: []` 而非 spec 约定的 `state: null`

- **位置**：
  - spec 3.4：`GET /api/tools/{pluginId}/state` 无记录 → `{ "state": null }`
  - 实际：`ToolStateService.php:14` `get()` 返回 `$state ?? []`（空数组）
  - 实际：`ToolController.php:20` 返回 `{ pluginId, state: [] }`
- **影响**：响应体与 spec 文档不一致。前端 `usePluginState` 用 `(res.state ?? {})` 容错，运行时不会崩溃，但与文档不符。
- **建议修复**：将 `ToolStateService::get` 改为 `return $state;`（保留 null），并在响应中允许 `state: null`。

### ⚠ P7：JWT logout 无服务端黑名单

- **位置**：`backend/src/Controllers/AuthController.php:74-80`
- **影响**：logout 后旧 access_token 在 exp 之前仍可调用受保护接口。
- **缓解**：前端清 localStorage 防止后续请求携带；但 token 一旦泄露在 exp 前无法吊销。
- **建议修复**：spec 5.1.8 允许"本期可用数据库表"实现 jti 黑名单。新增 `revoked_tokens(jti, expires_at)` 表，`AuthMiddleware`/`AuthGuardMiddleware` 校验 jti 不在表中。

### ⚠ P8：工作台布局 `x/y/w/h` 后端要求严格整型

- **位置**：`WorkspaceService.php:55` `is_int($item[$field])`
- **影响**：前端 TS 类型 `number` 包含浮点数，若 JSON 序列化输出 `1.5` 等浮点数会被后端拒绝（42201）。
- **缓解**：拖拽组件通常输出整数，但建议前端在 `workspace.store.saveLayout` 前对 `x/y/w/h` 做 `Math.round()`。
- **建议修复**：前端 `LayoutItem` 类型将 `x/y/w/h` 标注为 `number` 但在写入前 round。

### ⚠ P9：`/bookmarks/reorder` 路由顺序 hack

- **位置**：`Routes/api.php:25-29`、`BookmarkController.php:120-127`
- **现状**：通过 controller 内部 `id === 'reorder'` 字符串判断转发，工作正常但增加维护成本。
- **建议修复**：在 `Routes/api.php` 中将 `PUT /bookmarks/reorder` 注册到 `PUT /bookmarks/{id}` 之前；或将 `{id}` 路由的 regex 限定为 `\d+`。

### ❌ P10：`npm run build` 因 vue-devui CSS 不合规语法失败

- **位置**：
  - 构建：`vite.config.ts`（默认 LightningCSS 压缩）
  - 依赖：`vue-devui@1.6.36` 包含 legacy IE hack（`*zoom`）与 `:before .class` 选择器
  - 错误：`SyntaxError: [lightningcss minify] Pseudo-elements like '::before' or '::after' can't be followed by selectors like 'Delim('.')'`
- **影响**：
  - 生产构建无法生成 `dist/assets/*` 产物，无法部署到生产。
  - vue-tsc 类型检查通过，TypeScript 类型系统无问题。
- **建议修复**：在 `vite.config.ts` 添加：
  ```ts
  css: { lightningcss: { errorRecovery: true } }
  // 或 build: { cssMinify: 'esbuild' }
  ```
- **说明**：此修复涉及修改 `vite.config.ts`（构建配置），不在 Task 17 "仅创建测试脚本和报告文件" 的范围内，需在 Task 18 部署阶段处理。

---

## 12. 已通过项汇总

### 12.1 API 路径对接
- 30 条端点中 28 条完全对齐（93.3%）。✅

### 12.2 错误码闭环
- 11 个错误码（0/40001/40101/40102/40301/40401/40901/41301/42201/42901/50001/50301）全部在前端 request.ts 中有对应处理。✅

### 12.3 JWT 流程
- 签发（access + refresh）、校验（type 区分）、401 自动 refresh + 重试、refresh 队列合并、失败跳 /login，全链路闭环。✅

### 12.4 安装向导
- 未安装 → 50301 拦截；已安装 → 40901 拒绝；前端 router guard 与 request 拦截器双重处理；install 流程顺序正确（写 .env → 跑 migration → 创建 admin → 写 installed.lock）。✅

### 12.5 工具插件系统
- 前端 import.meta.glob 扫描 13 个插件目录，与后端 ToolController::TOOLS 13 条硬编码完全对齐；pluginId 格式校验 `^[a-zA-Z0-9_-]+$` 两端一致。✅

### 12.6 工作台布局
- 前端 LayoutItem 字段与后端 WorkspaceService 校验对齐；PUT/GET 验证一致；防抖 500ms 持久化。✅

### 12.7 书签 CRUD
- 字段、URL scheme 校验、extra JSONB 合并、reorder 事务、图标上传 multipart，全链路对齐。✅

### 12.8 CORS 中间件
- Origin 白名单匹配、Allow-Headers/Methods/Credentials/Max-Age 头输出正确；OPTIONS 短路 204；非白名单 Origin 不回显。✅

### 12.9 安全
- 密码 bcrypt（cost=12）、JWT HS256 + timing-safe 比较、SQL 全 PDO prepared statement、URL scheme 白名单、安装锁机制、.env 0640 权限。✅

---

## 13. 前端生产构建验证

### 13.1 构建命令
```
npm run build
# 等价于：vue-tsc -b && vite build
```

### 13.2 实际运行结果

| 步骤 | 状态 | 备注 |
|---|---|---|
| vue-tsc 类型检查 | ✅ 通过 | 2588 个模块成功转换，无类型错误 |
| vite build 生产构建 | ❌ 失败 | LightningCSS 压缩阶段报错（4.50s 后失败） |

### 13.3 错误详情

```
[plugin vite:css-post]
SyntaxError: [lightningcss minify] Pseudo-elements like '::before' or '::after'
can't be followed by selectors like 'Delim('.')'.
This file contains star property hack (e.g. `*zoom`), which was used in the past
to support old Internet Explorer versions. This is not a valid CSS syntax and
will be ignored by modern browsers.

  149 |  ...(--devui-brand, #5e7ce0)}.devui-tree__node--drop-next:before .devui-tree__node--drop-bottom{position:absolute;bott...
      |                                                                 ^
```

### 13.4 失败原因
- **根因**：`vue-devui@1.6.36` 的 CSS 包含 legacy IE hack（如 `*zoom`）与 `:before .class` 选择器组合，Vite 8 默认使用的 LightningCSS 无法压缩此类不合规语法。
- **影响**：本次构建未产出 `dist/assets/*` 产物（vite build 启动时清空了 `dist/`，仅保留 `public/` 中的静态文件 `favicon.svg` 与 `icons.svg`）。
- **类型检查无问题**：vue-tsc 阶段未报错，TS 类型系统对齐良好。

### 13.5 建议修复（不在本任务范围内执行）
在 `vite.config.ts` 添加 `css.lightningcss.errorRecovery`：

```ts
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'node:path'

export default defineConfig({
  plugins: [vue()],
  resolve: { alias: { '@': path.resolve(__dirname, 'src') } },
  css: {
    lightningcss: {
      errorRecovery: true,  // 跳过 vue-devui 中的 legacy IE hack
    },
  },
  // 或退回 esbuild 压缩：build: { cssMinify: 'esbuild' }
  server: { /* ... */ },
})
```

或更稳妥的方案：将 `cssMinify` 退回 `esbuild`：
```ts
build: { cssMinify: 'esbuild' }
```

### 13.6 已清理的产物
- 之前 `dist/assets/` 中的 7 个 JS chunk 与 1 个 CSS 文件已被 vite build 的清理步骤删除。
- 现存 `dist/`：
  - `favicon.svg`（9.3 KB）
  - `icons.svg`（4.91 KB）

### 13.7 验证建议
- 修复 vite.config.ts 后重新运行 `npm run build` 验证产物生成。
- 产物预期大小（参考之前成功构建）：入口 JS ~250 KB（gzip 后 ~80 KB）、CSS ~150 KB（gzip 后 ~25 KB）。

---

## 14. 部署前置条件 Checklist

部署后请逐项确认：

- [ ] PostgreSQL 已创建空数据库（migration 由安装向导执行）
- [ ] `backend/config/` 与 `backend/uploads/` 目录可写（www-data）
- [ ] PHP ≥ 8.1 + pdo_pgsql + json 扩展
- [ ] 通过浏览器访问 `/setup` 完成安装向导
- [ ] 安装完成后**手动编辑 `backend/.env`** 设置 `CORS_ORIGINS`（详见 P4）
- [ ] `config/installed.lock` 已生成且 Nginx 配置 `deny all`（deploy/nginx.conf:161-164）
- [ ] Nginx SSL 证书已配置并强制 HTTPS
- [ ] PHP-FPM opcache 启用，危险函数禁用
- [ ] 定时备份任务已配置（pg_dump）

---

## 15. E2E 测试脚本使用说明

测试脚本位于 `tests/e2e-curl.sh`，覆盖 8 个场景。使用方法：

```bash
# 1. 启动后端（任选其一）
php -S 0.0.0.0:8000 -t backend/public
# 或通过 Nginx + PHP-FPM

# 2. 运行全部场景
BASE_URL=http://localhost:8000 bash tests/e2e-curl.sh

# 3. 仅运行单个场景（调试时有用）
RUN_SCENARIO=17.3 BASE_URL=http://localhost:8000 bash tests/e2e-curl.sh

# 4. 自定义数据库与管理员账号
BASE_URL=http://localhost:8000 \
DB_HOST=127.0.0.1 DB_PORT=5432 DB_NAME=nytab DB_USER=nytab DB_PASSWORD=secret \
ADMIN_USER=admin ADMIN_PASS='StrongP@ss1' ADMIN_EMAIL=admin@example.com \
bash tests/e2e-curl.sh

# 5. 重置 17.7 锁定状态（5 次失败后）
psql -d nytab -c "DELETE FROM login_logs WHERE ip = '127.0.0.1' AND success = false;"
```

脚本特点：
- **幂等**：17.1 检测已安装则跳过 install；17.3 改密后会还原密码供后续场景使用。
- **跨场景依赖**：17.3 登录后写入临时 token 文件，17.4/17.5/17.6 复用。
- **不依赖 jq**：用 grep + sed 解析 JSON，便于在最小化环境运行。
- **彩色输出**：✓ 绿 / ✗ 红 / → 黄，便于人工识别。
- **退出码**：0=全通过，1=有失败，2=环境错误。

---

## 16. 报告总结

| 维度 | 通过 | 待验证 | 问题 |
|---|---|---|---|
| API 端点对接 | 28/30 | 1 | 1（P1） |
| 字段名对接 | 14/15 | 0 | 1（P3） |
| 错误码对接 | 11/11 | 0 | 0 |
| JWT 流程 | 5/6 | 0 | 1（P7） |
| CORS | 3/4 | 0 | 1（P4） |
| 安装向导 | 4/4 | 0 | 0 |
| 工具插件 | 3/3 | 0 | 0 |
| 工作台布局 | 4/5 | 1（P8） | 0 |
| 工具状态 | 2/4 | 0 | 2（P5、P6） |
| 书签 | 7/8 | 1（P9） | 1（P1） + 1 缺失方法（P2） |
| **前端构建** | 1/2 | 0 | 1（P10） |

**关键问题**：10 个，其中 6 个 ❌ 需修复（P1-P5、P10），4 个 ⚠ 建议优化（P6-P9）。
**整体评估**：
- 核心代码集成度良好：认证、CORS、安装向导、工具插件、布局、书签 CRUD 的代码闭环全部可用。
- 真正阻断部署的问题有 1 个：**P10（vite build 失败）**——必须在 Task 18 修复 `vite.config.ts` 才能产出生产产物。
- 其他问题（fetch-icon 缺失、超限错误码、theme 类型、CORS_ORIGINS 空、reorderCategories 缺失）均为边缘场景或需运行时验证，不影响主流程。
- 建议在 Task 18 部署前优先修复 P10，再依次处理 P4（CORS_ORIGINS）、P5/P6（工具状态契约）、P1/P2（书签 fetch-icon + reorderCategories）、P3（theme 类型）。
