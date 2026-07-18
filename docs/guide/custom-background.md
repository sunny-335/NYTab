# 自定义背景

NYTab 支持三种背景模式:Bing 每日壁纸、图片 API 链接、本地上传。可按设备尺寸自动切换 PC / 移动端壁纸。

## 入口

1. 登录 NYTab
2. 进入 **设置 → 背景**

页面会实时预览当前选择,保存后立即在全站生效(`<Background />` 组件响应式更新)。

## 三种背景类型

### 1. Bing 每日壁纸(默认)

| 项目 | 说明 |
| --- | --- |
| 数据源 | [https://bing.img.run/api.html](https://bing.img.run/api.html) |
| 刷新策略 | 每日自动刷新 |
| 设备适配 | ≥ 768px 用 PC 壁纸,< 768px 用移动端壁纸 |
| 配置项 | 无需配置,直接保存即可 |

Bing 模式下,后端不需要存储任何 URL,前端根据 `useBreakpoint()` 判断设备尺寸后直接拼接请求地址:

```
https://bing.img.run/api.html?type=pc       // PC 端
https://bing.img.run/api.html?type=mobile   // 移动端
```

::: tip 💡 为什么按设备尺寸切换?
Bing 每日壁纸的 PC 版本是横屏 1920×1080,在手机上会被裁剪得很难看;
移动版本是竖屏,在 PC 上又会有黑边。NYTab 自动按视口宽度选择合适版本。
:::

### 2. API 链接

填入一个返回图片的 URL,直接作为背景图加载。

| 项目 | 说明 |
| --- | --- |
| 字段 | `url` |
| 必填 | 是 |
| 校验 | URL 格式合法(后端校验字符串长度 ≤ 512 字符) |
| 设备适配 | 不区分 PC / 移动端,所有设备用同一张图 |

适合场景:

- 你有自己的图片源 API(如 `https://picsum.photos/1920/1080`)
- 公司内网有内部图片服务
- 想固定使用某张在线图片

::: warning ⚠️ 跨域与防盗链
若图片服务器启用了 Referer 防盗链或 CORS 限制,可能加载失败。
建议优先使用允许跨域的图床或自己的对象存储。
:::

### 3. 上传图片

从本地选择图片上传,NYTab 后端保存到 `backend/uploads/backgrounds/`。

| 项目 | 说明 |
| --- | --- |
| 支持格式 | JPG / PNG / WebP |
| 大小限制 | ≤ 5 MB |
| 存储位置 | `backend/uploads/backgrounds/<uuid>.<ext>` |
| 访问路径 | `/uploads/backgrounds/<uuid>.<ext>`(由 Nginx 直接 serve) |

上传成功后,背景类型会自动切到「上传图片」模式,并把返回的 URL 填入 `formUrl`,点「保存」即可全站应用。

::: tip 💡 上传后原文件不会被删除
重新上传或切换到其他背景模式时,之前上传的图片文件 **保留** 在 `uploads/backgrounds/` 中。
若需要清理,可手动删除该目录下的旧文件。
:::

## 设备尺寸切换

NYTab 通过 `useBreakpoint()` composable 判断设备类型:

| 视口宽度 | 设备类型 | Bing 壁纸版本 |
| --- | --- | --- |
| ≥ 768px | PC | `?type=pc` |
| < 768px | 移动端 | `?type=mobile` |

::: warning ⚠️ 仅 Bing 模式自动切换
设备尺寸自动切换 **只对 Bing 模式生效**。
API 链接和上传图片模式不区分 PC / 移动端,所有设备加载同一张图。
若你想为移动端单独提供一张图,可以让你的图片 API 自己根据 `User-Agent` 返回不同 URL。
:::

## Bing 壁纸 API 详解

NYTab 默认使用第三方 Bing 壁纸代理服务 [https://bing.img.run/api.html](https://bing.img.run/api.html),支持以下查询参数:

| 参数 | 取值 | 说明 |
| --- | --- | --- |
| `type` | `pc` / `mobile` | PC 横屏 / 移动端竖屏 |
| `day` | `0` ~ `7` | 0=今天(默认),1=昨天,以此类推 |

NYTab 默认请求 `?type=pc` 或 `?type=mobile`(根据视口),`day` 不传(取今天)。

::: tip 💡 想换其他 Bing 壁纸源?
后端 `BackgroundSettings.vue` 中的 `BING_BASE` 常量定义了壁纸源 URL。
若 `bing.img.run` 不可用,可修改源码替换为其他 Bing 壁纸代理(如 `https://bing.shenzhuolin.com/api`),或自建代理。
:::

## 数据存储

背景配置保存在 `system_settings.background` JSONB 字段中,结构如下:

```json
{
  "type": "bing",     // "bing" | "api" | "image"
  "url": ""           // 仅 "api" / "image" 模式有值
}
```

- `type = "bing"` 时,`url` 为空,前端拼接 Bing API
- `type = "api"` 或 `"image"` 时,`url` 为图片地址

## 实时预览

设置页右下角的「实时预览」框会根据当前表单状态(未保存)即时显示背景效果:

- Bing 模式:根据当前视口宽度拼接 `?type=pc` 或 `?type=mobile`
- API 模式:直接加载 `formUrl` 输入框中的 URL
- 上传模式:加载刚上传返回的 URL

预览框采用 16:9 宽高比,所见即所得。

## 故障排查

### 背景不显示

1. 打开浏览器 DevTools → Network,过滤 `api.html` 或你的图片 URL,确认请求 200 且 `Content-Type` 是 `image/*`
2. 若是 API 链接模式,确认 URL 直接访问能返回图片(在地址栏粘贴)
3. 若是上传模式,确认 `/uploads/backgrounds/<文件名>` 返回 200;若 404,通常是 Nginx 没正确配置 `location /uploads/`

### 背景图加载慢

Bing 每日壁纸代理 `bing.img.run` 偶尔会慢,这是上游服务问题。
若长期慢,可改用 API 链接模式指向你自己的图片 CDN。

### 上传失败提示「图片大小不能超过 5MB」

前端在 `BackgroundSettings.vue::onFileChange()` 中已做 5MB 校验。
请先用图片处理工具压缩后再上传,或改用 API 链接模式。

## 相关代码

- `src/views/settings/BackgroundSettings.vue` —— 设置页 UI 与上传逻辑
- `src/components/Background.vue` —— 全站背景渲染组件
- `src/stores/background.store.ts` —— Pinia store
- `src/composables/useBreakpoint.ts` —— 设备尺寸判断
- `backend/src/Controllers/...` —— 后端接收上传与配置读写

## 下一步

- [品牌自定义](/guide/brand-customization):搭配修改昵称 / Logo
- [快捷键](/guide/shortcuts):加速日常操作
