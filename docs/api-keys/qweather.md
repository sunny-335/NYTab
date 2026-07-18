# 和风天气 API 密钥

NYTab 的天气小组件支持和风(QWeather)天气数据源。本页介绍如何申请 API Key 并配置到 NYTab。

## 申请步骤

### 1. 访问和风开发者平台

打开 [https://dev.qweather.com/](https://dev.qweather.com/),点击右上角「控制台」。

### 2. 注册账号

选择「免费订阅」(对个人开发者足够使用),使用邮箱注册并完成邮箱验证。

::: tip 💡 免费订阅额度
免费订阅每日 1000 次调用,接入 NYTab 后由于 30 分钟服务端缓存,实际消耗极低。
:::

### 3. 创建应用

登录控制台后,左侧菜单选择「应用管理」,点击「创建应用」:

| 字段 | 示例 |
| --- | --- |
| 应用名称 | NYTab |
| 应用类型 | Web API |

### 4. 添加 Key

在创建好的应用下,系统会自动生成一个 Key(32 位字符串,例如 `a1b2c3d4e5f6...`)。
复制保存这个 Key。

::: warning ⚠️ 区分 Key 与 LocationID
- **Key** 是访问和风 API 的凭据(32 位字符串)
- **LocationID** 是城市标识(数字 ID,如 `101010100` 代表北京)

NYTab 配置里填的是 **Key**,LocationID 由 NYTab 通过城市搜索自动转换。
:::

## 配置到 NYTab

1. 登录 NYTab
2. 进入 **设置 → 天气**
3. 数据源选择 **「和风」**
4. 在「API Key」字段粘贴刚才复制的 Key
5. 保存

## LocationID 自动转换

和风天气 API 与高德不同,**不接受城市名,只接受 LocationID**。例如查询北京天气需要传 `location=101010100`,而不是 `Beijing`。

NYTab 自动处理这一差异:

1. 在 **设置 → 天气** 输入城市名(如「北京」)
2. NYTab 调用和风城市搜索 API `/v2/city/lookup?location=北京` 拿到候选列表
3. 用户选中目标城市后,系统记录其 LocationID
4. 后续天气查询直接用 LocationID 调用 `/v7/weather/now`

::: tip 💡 不用手动查 LocationID
如需手动查询 LocationID,参考和风官方文档:
[https://dev.qweather.com/docs/api/location/](https://dev.qweather.com/docs/api/location/)
:::

## 免费订阅额度

| 项目 | 限制 |
| --- | --- |
| 每日调用次数 | 1000 次 / 日 |
| QPS | 10 次 / 秒 |
| 单次响应缓存 | NYTab 后端 30 分钟 |

NYTab 后端在 `system_settings.weather_cache` 中维护 30 分钟缓存,实际配额消耗远低于调用次数。

## 接口调用细节

NYTab 后端实际调用的和风接口:

| 接口 | 用途 |
| --- | --- |
| `GET /v7/weather/now?location=<LocationID>` | 拉取当前实况天气 |
| `GET /v2/city/lookup?location=<keyword>` | 城市搜索(LocationID 转换) |

所有请求由 PHP 后端代理,前端不直接访问和风 API,Key 不会暴露到浏览器。

::: warning ⚠️ 仅拉取实况天气
当前 NYTab 后端只调用 `/v7/weather/now` 拉取实况天气,**不包含**多日预报。
如果你需要预报能力,推荐使用 [高德天气](/api-keys/amap-weather)(支持 4 天预报)。
:::

## 故障排查

### 提示「和风 API Key 未配置」

进入 **设置 → 天气**,确认数据源选了「和风」且 API Key 字段非空。保存后刷新页面。

### 提示「和风天气 API 调用失败 code=401/402/403」

- `code = 401` —— Key 错误或被禁用,到控制台核对
- `code = 402` —— 超出免费订阅配额,次日自动恢复或升级付费订阅
- `code = 403` —— 无权限访问该接口,检查订阅类型

### 提示「和风天气 API 调用失败 code=404」

LocationID 不存在或参数缺失。重新在设置页选择城市,让 NYTab 重新走城市搜索流程。

### 天气不刷新

NYTab 后端缓存 30 分钟,缓存期间不会重新请求和风。如需立即刷新,等待 30 分钟或清空 `system_settings.weather_cache` 记录。

## 相关代码

- `backend/src/Services/WeatherService.php::getHeFengWeather()` —— 和风天气代理
- `backend/src/Controllers/WeatherController.php` —— 天气路由入口

## 下一步

- [高德天气 API 密钥](/api-keys/amap-weather):另一家天气数据源,支持多日预报
- [品牌自定义](/guide/brand-customization):修改昵称 / Title / Logo
