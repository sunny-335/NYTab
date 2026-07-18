# 插件开发

NYTab 的工作台由「工具插件」组成。每个插件是一个独立的 Vue 异步组件 + 元数据描述,放在 `src/plugins/tools/<name>/` 目录下即可被自动注册。

本页介绍如何从零编写一个工具插件。

## 目录结构

最小插件由两个文件组成:

```
src/plugins/tools/
├── _shared/                 # 共享工具(可选)
├── base64/                  # 已有插件示例
│   ├── Base64.vue
│   └── index.ts
├── counter/                 # 你将创建的插件
│   ├── Counter.vue
│   └── index.ts
├── registry.ts              # 自动注册表(import.meta.glob)
└── types.ts                 # defineToolPlugin 辅助函数
```

- `index.ts` —— 导出 `defineToolPlugin({ meta, component, defaultState? })`
- `Component.vue` —— 插件 UI,通过 `<component :is>` 渲染到工作台卡片中

## 插件接口

类型定义在 `src/types/plugin.d.ts`:

```typescript
import type { Component } from 'vue'

export type ToolCategory = 'efficiency' | 'developer' | 'lifestyle'

export interface ToolPluginMeta {
  /** 唯一 ID,匹配 ^[a-zA-Z0-9_-]+$,长度 1-64 */
  pluginId: string
  /** 显示名称 */
  name: string
  /** 分类 */
  category: ToolCategory
  /** 图标(emoji 或 URL,可选) */
  icon?: string
  /** 描述(可选) */
  description?: string
  /** 默认布局尺寸(栅格单元,可选) */
  defaultSize?: { w: number; h: number }
  /** 最小尺寸(可选) */
  minSize?: { w: number; h: number }
  /** 是否允许在工作台关闭(默认 true) */
  dismissible?: boolean
}

export interface ToolPlugin {
  meta: ToolPluginMeta
  /** Vue 3 异步组件定义(用于 <component :is>) */
  component: () => Promise<{ default: Component }>
  /** 可选:首次使用、后端无 state 时返回的默认 state */
  defaultState?: () => Record<string, unknown>
}
```

`src/plugins/tools/types.ts` 提供了一个 `defineToolPlugin` 辅助函数,作用类似 Vite 的 `defineConfig`,仅用于在 IDE 中获得类型提示:

```typescript
import type { ToolPlugin } from '@/types/plugin'

export function defineToolPlugin(plugin: ToolPlugin): ToolPlugin {
  return plugin
}
```

## 自动注册

`src/plugins/tools/registry.ts` 使用 Vite 的 `import.meta.glob` 扫描所有 `./<id>/index.ts`:

```typescript
const pluginModules = import.meta.glob<Record<string, unknown>>('./*/index.ts')
```

每个插件会成为独立的异步 chunk,**新增目录即自动注册**,无需修改 `registry.ts`。

工作台初始化时调用 `toolApi.registry()` 拉取后端清单(用于分类、显示名称等元数据);后端清单的静态回退映射在 `registry.ts::listPluginsByCategory()` 中维护:

```typescript
return {
  efficiency: ['pomodoro', 'markdown', 'notes', 'clock'],
  developer: ['code-format', 'json-xml', 'base64', 'regex', 'color-picker'],
  lifestyle: ['exchange', 'unit-convert', 'password-gen', 'qrcode'],
}
```

::: warning ⚠️ 新增插件需要同步两处
为了让分类显示正确,新插件除了在 `src/plugins/tools/<id>/` 创建文件外,
**还要**在 `registry.ts::listPluginsByCategory()` 中把 pluginId 加到对应分类数组里。
:::

## usePluginState composable

`src/composables/usePluginState.ts` 提供了响应式 state + 自动持久化的封装。一行代码即可获得「从后端拉取初始 state → 深度监听变更 → 防抖自动保存」的完整能力。

```typescript
import { usePluginState } from '@/composables/usePluginState'

const { state, loading, saving, error, save, reload, patch } = usePluginState<{
  count: number
}>('my-tool', {
  defaultState: () => ({ count: 0 }),
  debounceMs: 800, // 默认 800ms
})
```

返回值说明:

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `state` | `Ref<T \| null>` | 响应式 state,`onMounted` 时自动从后端拉取并与 `defaultState` 合并 |
| `loading` | `Ref<boolean>` | 首次拉取中 |
| `saving` | `Ref<boolean>` | 持久化中 |
| `error` | `Ref<Error \| null>` | 最近一次拉取/保存错误 |
| `save()` | `() => Promise<void>` | 立即保存(取消挂起的防抖定时器) |
| `reload()` | `() => Promise<void>` | 重新从后端拉取 |
| `patch(partial)` | `(Partial<T>) => void` | 浅合并部分字段到当前 state(会触发防抖保存) |

::: tip 💡 工作机制
- 深度 `watch(state)` + 防抖 800ms,自动调用 `toolApi.saveState(pluginId, state)`
- `onUnmounted` 时若有挂起的变更,会立即同步保存
- `reload` 期间会跳过 watch 触发的自动保存,避免回写覆盖
:::

## 完整示例:计数器插件

下面创建一个简单的计数器插件,展示完整的开发流程。

### 1. 创建目录与 index.ts

`src/plugins/tools/counter/index.ts`:

```typescript
import { defineToolPlugin } from '../types'

export default defineToolPlugin({
  meta: {
    pluginId: 'counter',
    name: '计数器',
    category: 'efficiency',
    icon: '🔢',
    description: '一个简单的计数器演示插件',
    defaultSize: { w: 4, h: 3 },
    minSize: { w: 2, h: 2 },
  },
  component: () => import('./Counter.vue'),
  defaultState: () => ({ count: 0, history: [] as number[] }),
})
```

### 2. 创建组件

`src/plugins/tools/counter/Counter.vue`:

```vue
<script setup lang="ts">
import { usePluginState } from '@/composables/usePluginState'

interface CounterState {
  count: number
  history: number[]
}

const { state, loading, saving, patch, save } = usePluginState<CounterState>(
  'counter',
  { defaultState: () => ({ count: 0, history: [] }) },
)

function increment() {
  if (!state.value) return
  patch({
    count: state.value.count + 1,
    history: [...state.value.history, state.value.count + 1].slice(-20),
  })
}

function reset() {
  patch({ count: 0, history: [] })
  void save() // 立即保存,不等防抖
}
</script>

<template>
  <div class="counter">
    <div v-if="loading" class="counter__loading">加载中…</div>
    <template v-else-if="state">
      <div class="counter__value">{{ state.count }}</div>
      <div class="counter__actions">
        <button @click="increment">+1</button>
        <button @click="reset">重置</button>
      </div>
      <div class="counter__history">
        最近:{{ state.history.slice(-5).join(', ') || '暂无' }}
      </div>
      <div v-if="saving" class="counter__saving">保存中…</div>
    </template>
  </div>
</template>

<style scoped>
.counter {
  padding: 16px;
  text-align: center;
}
.counter__value {
  font-size: 36px;
  font-weight: 600;
  margin-bottom: 12px;
}
.counter__actions {
  display: flex;
  gap: 8px;
  justify-content: center;
}
.counter__actions button {
  padding: 6px 16px;
  border: 1px solid #e5e6eb;
  background: #fff;
  border-radius: 4px;
  cursor: pointer;
}
.counter__history {
  margin-top: 12px;
  font-size: 12px;
  color: #86909c;
}
.counter__saving {
  margin-top: 8px;
  font-size: 11px;
  color: #86909c;
}
</style>
```

### 3. 注册到分类清单

编辑 `src/plugins/tools/registry.ts`,把 `counter` 加到 `listPluginsByCategory` 的 `efficiency` 数组里:

```typescript
return {
  efficiency: ['pomodoro', 'markdown', 'notes', 'clock', 'counter'],
  developer: ['code-format', 'json-xml', 'base64', 'regex', 'color-picker'],
  lifestyle: ['exchange', 'unit-convert', 'password-gen', 'qrcode'],
}
```

### 4. 启动并验证

```bash
npm run dev
```

打开浏览器访问 NYTab,在「工作台 → 添加工具」中应能看到「计数器」插件,点击即可拖入工作台使用。

## 调试技巧

### 在开发者模式下调试

启用 [开发者模式](/dev/developer-mode) 后,所有 `usePluginState` 的读写会落到 SQLite(`backend/storage/nytab_dev.sqlite`)。
你可以直接用 `sqlite3` CLI 查看状态:

```bash
sqlite3 backend/storage/nytab_dev.sqlite \
  "SELECT plugin_id, state FROM tool_states WHERE plugin_id = 'counter';"
```

### 查看网络请求

`usePluginState` 通过 `toolApi.getState` / `toolApi.saveState` 与后端通信:

- `GET /api/tools/:pluginId/state` —— 拉取 state
- `PUT /api/tools/:pluginId/state` —— 保存 state

打开浏览器 DevTools → Network,过滤 `tools` 即可看到所有读写请求与响应。

### 强制刷新 state

若怀疑 state 与后端不同步,可在组件中调用 `reload()`:

```typescript
const { reload } = usePluginState('counter', { defaultState: () => ({ count: 0 }) })

// 比如手动刷新按钮
<button @click="reload">刷新</button>
```

## 后端状态存储

工具状态保存在 `tool_states` 表中,结构如下:

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | `bigint` | 主键 |
| `user_id` | `bigint` | 所属用户 |
| `plugin_id` | `varchar(64)` | 插件 ID(对应 `meta.pluginId`) |
| `state` | `jsonb` | 状态 JSON |
| `updated_at` | `timestamptz` | 最后更新时间 |

同一用户同一插件的 state 唯一(`UNIQUE(user_id, plugin_id)`),由后端 `ToolStateService` 通过 `ON CONFLICT DO UPDATE` 实现 upsert。

## 下一步

- 阅读 `src/plugins/tools/notes/index.ts` + `Notes.vue` 了解更复杂插件的真实实现
- 阅读 `src/composables/usePluginState.ts` 了解防抖保存细节
- [开发者模式](/dev/developer-mode):本地快速调试插件
