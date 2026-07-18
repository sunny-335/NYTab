# 高德天气 API 密钥

NYTab 的天气小组件支持高德(AMap)天气数据源。本页介绍如何申请 API Key 并配置到 NYTab。

## 申请步骤

### 1. 访问高德开放平台

打开 [https://lbs.amap.com/](https://lbs.amap.com/),点击右上角「登录/注册」。

### 2. 注册账号

可使用手机号注册,或直接用支付宝 / 微信扫码登录。

### 3. 进入控制台 → 应用管理

登录后进入 [控制台](https://console.amap.com/dev/key/app),左侧菜单选择「应用管理 → 我的应用」。

### 4. 创建新应用

点击「创建新应用」,填写:

| 字段 | 示例 |
| --- | --- |
| 应用名 | NYTab |
| 应用类型 | 其他 |
| 描述 | NYTab 标签页天气数据 |

### 5. 添加 Key

在新建好的应用下点击「添加 Key」:

| 字段 | 选择 |
| --- | --- |
| Key 名称 | NYTab-Web |
| 服务平台 | **Web 服务** ⚠️ 必须选这个,不要选「Web 端(JS API)」 |
| 其他字段 | 默认即可 |

提交后页面会显示一串 32 位的 Key 字符串,例如 `a1b2c3d4e5f6...`,复制保存。

::: warning ⚠️ 服务平台必须选「Web 服务」
NYTab 后端是直接调用高德 REST API(`/v3/weather/weatherInfo`、`/v3/geocode/regeo` 等),
只有「Web 服务」类型的 Key 才能访问这些接口。选错类型会返回 `INVALID_USER_SCODE` 错误。
:::

## 配置到 NYTab

1. 登录 NYTab
2. 进入 **设置 → 天气**
3. 数据源选择 **「高德」**
4. 在「API Key」字段粘贴刚才复制的 Key
5. 保存

配置完成后,时钟插件会自动调用高德 API 显示当前城市的天气与未来 4 天预报。

## 城市编码查询

高德天气 API 需要传入城市的 `adcode`(行政区划代码,6 位数字)。NYTab 内置了城市搜索功能,你只需要:

1. 在 **设置 → 天气** 中输入城市名(如「北京」「上海」「深圳」)
2. 系统调用高德地理编码 API 自动转换为 adcode
3. 选中匹配的城市后保存

如果你想查看完整 adcode 列表或手动查询,参考高德官方文档:
[https://lbs.amap.com/api/webservice/guide/api/weather](https://lbs.amap.com/api/webservice/guide/api/weather)

## 自动定位

NYTab 支持基于浏览器 Geolocation + 高德逆地理编码的自动定位:

1. 在 **设置 → 天气** 中开启「自动定位」
2. 浏览器会弹出位置授权请求,选择「允许」
3. NYTab 拿到经纬度后调用高德 `/v3/geocode/regeo` 接口反查 adcode
4. 自动设置当前城市

::: tip 💡 浏览器 Geolocation 需要 HTTPS
浏览器只在 `https://` 或 `http://localhost` 下才会启用 Geolocation。
若通过 HTTP 部署,自动定位功能将不可用,需要手动选择城市。
:::

::: warning ⚠️ 直辖市特殊处理
高德逆地理对直辖市(北京/上海/天津/重庆)和省直辖县返回的 `city` 字段可能是空数组。
NYTab 后端已对此做兜底:遇到空 `city` 时回退使用 `province` 作为城市名。
:::

## 免费额度

| 项目 | 限制 |
| --- | --- |
| 个人开发者每日配额 | 5000 次 / 日 |
| 单次响应缓存 | NYTab 后端 30 分钟 |

NYTab 后端在 `system_settings.weather_cache` 中维护 30 分钟缓存,**同一城市的多次请求只算一次配额**。
按典型使用强度(每天查 5-10 次天气),个人配额远远用不完。

## 接口调用细节

NYTab 后端实际调用的高德接口:

| 接口 | 用途 |
| --- | --- |
| `GET /v3/weather/weatherInfo?extensions=all` | 拉取天气与多日预报 |
| `GET /v3/geocode/regeo` | 经纬度 → adcode(自动定位用) |
| `GET /v3/geocode/geo` | 城市名 → adcode(城市搜索用) |

所有请求由 PHP 后端代理,前端不直接访问高德 API,Key 不会暴露到浏览器。

## 故障排查

### 提示「高德 API Key 未配置」

进入 **设置 → 天气**,确认数据源选了「高德」且 API Key 字段非空。保存后刷新页面。

### 提示「高德天气 API 调用失败: INVALID_USER_SCODE」

Key 类型选错了。回到高德控制台,确认 Key 的服务平台是「Web 服务」,不是「Web 端(JS API)」或「Android / iOS」。

### 提示「高德天气 API 调用失败: USERKEY_PLAT_NOMATCH」

同上,Key 与平台不匹配。重新创建一个「Web 服务」类型的 Key。

### 天气不刷新

NYTab 后端缓存 30 分钟,缓存期间不会重新请求高德。如需立即刷新,等待 30 分钟或清空 `system_settings.weather_cache` 记录。

## 相关代码

- `backend/src/Services/WeatherService.php::getGaodeWeather()` —— 高德天气代理
- `backend/src/Services/WeatherService.php::reverseGeocode()` —— 自动定位反查
- `backend/src/Services/WeatherService.php::searchCities()` —— 城市搜索

## 下一步

- [和风天气 API 密钥](/api-keys/qweather):另一家天气数据源
- [品牌自定义](/guide/brand-customization):修改昵称 / Title / Logo
