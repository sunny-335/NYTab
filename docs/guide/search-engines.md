# 搜索引擎与智能书签搜索

NYTab 顶部搜索栏整合了 **6 个搜索引擎** 与 **智能书签搜索**,支持键盘全流程操作。

## 搜索引擎切换

点击搜索栏左侧的引擎图标,弹出下拉菜单选择目标引擎。

### 内置 6 个引擎

| 引擎 ID | 名称 | 搜索 URL |
| --- | --- | --- |
| `bing` | Bing(默认) | `https://www.bing.com/search?q={query}` |
| `google` | Google | `https://www.google.com/search?q={query}` |
| `baidu` | 百度 | `https://www.baidu.com/s?wd={query}` |
| `duckduckgo` | DuckDuckGo | `https://duckduckgo.com/?q={query}` |
| `sogou` | 搜狗 | `https://www.sogou.com/web?query={query}` |
| `360` | 360 | `https://www.so.com/s?q={query}` |

引擎清单是常量(定义在 `src/composables/useSearchEngines.ts`),目前不支持自定义。

## 引擎记忆策略

进入 **设置 → 搜索引擎 → 搜索引擎记忆策略** 配置:

### 恢复默认(restore,默认)

每次启动 NYTab 时,搜索栏恢复使用 Bing。

- 适合多用户共享设备的场景
- 切换引擎后只在当前会话生效,刷新页面会重置回 Bing

### 记住选择(remember)

记住上次选择的引擎,下次启动仍保留。

- 适合个人设备
- 切换引擎后,即使关闭浏览器再打开,仍使用上次选择的引擎

策略存储在浏览器 `localStorage`,不与后端同步。

## 智能书签搜索

搜索栏整合了书签搜索与搜索联想两大能力,通过 **搜索模式** 控制下拉框内容。

进入 **设置 → 搜索引擎 → 搜索栏建议模式** 配置:

### 混合模式(mixed,默认)

下拉框同时显示:

- 上方:**书签结果**(最多 5 条),匹配书签名/URL/分类
- 下方:**搜索联想**(8 条),来自 Bing 官方搜索建议 API

最常用的模式,既能快速访问已有书签,又能看到搜索联想。

### 仅搜索联想(suggestions)

下拉框只显示 Bing 搜索联想(8 条),不显示书签结果。

适合:你的书签较少,主要把 NYTab 当搜索引擎用。

### 仅书签(bookmarks)

下拉框只显示匹配的书签结果,不显示搜索联想。

适合:你把 NYTab 当书签管理器用,不关心搜索联想。

::: tip 💡 配合快捷键
内置快捷键 `Z + S` 可在 `mixed` 与 `bookmarks` 模式之间快速切换,无需进设置页。详见 [快捷键](/guide/shortcuts)。
:::

## 键盘导航

搜索栏下拉框支持完整的键盘操作:

| 按键 | 行为 |
| --- | --- |
| `↑` / `↓` | 在结果列表中上下选择 |
| `Enter` | 确认选择:执行搜索(用当前引擎打开)或打开书签 |
| `Esc` | 关闭下拉框 |
| `Tab` | 在书签结果与联想词之间分组跳转(若混合模式) |

选中书签结果时按 Enter,直接在新标签页打开该书签。
选中搜索联想时按 Enter,用当前引擎搜索该词。

## 搜索联想 API

搜索联想调用 **Bing 官方搜索建议 API**,无需 API Key:

```
https://www.bing.com/AS/Suggestions?mkt=zh-cn&qry=<keyword>&cvid=<uuid>
```

::: tip 💡 无需密钥
Bing 的搜索建议接口是公开的,不需要申请 API Key。
NYTab 前端直接调用,请求会带上浏览器的 Cookie,但 NYTab 自身不存储任何 Bing 凭据。
:::

::: warning ⚠️ 联想词偶尔为空
Bing 联想 API 在以下场景可能返回空数组:
- 关键字过短(1 个字符)
- 关键字包含特殊符号
- Bing 服务降级(高峰期偶发)

这是上游 API 行为,非 NYTab bug。下拉框会自动回退为只显示书签结果。
:::

## 引擎与模式的数据存储

| 配置 | 存储位置 | 同步 |
| --- | --- | --- |
| 当前引擎 ID | 浏览器 `localStorage` | 不同步 |
| 引擎记忆策略 | 浏览器 `localStorage` | 不同步 |
| 搜索模式 | 浏览器 `localStorage` | 不同步 |
| 书签数据 | 后端 `bookmarks` 表 | 多端同步 |

::: warning ⚠️ 配置不同步
搜索引擎相关配置(策略、模式、当前引擎)只存在浏览器本地,换浏览器/设备需要重新配置。
书签数据存在后端,所有设备共享。
:::

## 故障排查

### 切换引擎后下次启动又变回 Bing

确认 **设置 → 搜索引擎 → 记忆策略** 选了「记住我上次的选择」。
若选的是「恢复默认」,每次启动都会重置回 Bing。

### 搜索联想不显示

1. 检查网络是否能访问 `www.bing.com`(国内某些网络环境可能不稳定)
2. 尝试切换关键字(某些关键字确实没有联想词)
3. 确认 **设置 → 搜索引擎 → 搜索模式** 不是「仅书签」(该模式下不显示联想)

### 书签搜索结果不显示

1. 确认你有书签数据(进「书签管理」页查看)
2. 确认 **设置 → 搜索引擎 → 搜索模式** 不是「仅搜索联想」(该模式下不显示书签)
3. 输入更具体的关键字,书签匹配需要关键字长度 ≥ 1 字符

## 相关代码

- `src/components/SearchBar.vue` —— 搜索栏 UI 与键盘导航
- `src/composables/useSearchEngines.ts` —— 引擎清单与 URL 构造
- `src/stores/search.store.ts` —— 引擎/模式/策略的 Pinia store
- `src/views/settings/SearchSettings.vue` —— 设置页 UI

## 下一步

- [快捷键](/guide/shortcuts):用 `S` 聚焦搜索栏、`Z+S` 切换搜索模式
- [品牌自定义](/guide/brand-customization):修改昵称 / Title / Logo
