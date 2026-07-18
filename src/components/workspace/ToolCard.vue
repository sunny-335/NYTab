<script setup lang="ts">
import { computed, defineAsyncComponent, type Component } from 'vue'
import { resolveComponent } from '@/plugins/tools/registry'

/**
 * ToolCard — 工具卡片容器。
 *
 * 使用 Vue DevUI 的 `d-card` 包裹，通过 `resolveComponent(pluginId)` 获取
 * 异步组件 loader，再用 `defineAsyncComponent` 包装，最后用 `<Suspense>`
 * 提供加载占位。
 *
 * 注意：vue-devui 的 `d-card` 没有 `header` slot，只有 `title` / `subtitle` /
 * `content` / `actions` / `avatar` / `default`。这里用 `title` slot 渲染图标 +
 * 名称，`content` slot 渲染动态组件。
 *
 * 由于外层 WorkspaceGrid 已提供卡片边框/拖拽栏，本组件通过 scoped 样式
 * 抹平 d-card 自带的边框与阴影，避免视觉嵌套。
 */
const props = defineProps<{
  pluginId: string
  title: string
  icon?: string
  loading?: boolean
}>()

defineEmits<{
  (e: 'remove'): void
}>()

/**
 * 异步组件（pluginId 变化时重新解析）。
 * loader 内部将 `{ default: Component }` 解包为 `Component`，
 * 以匹配 defineAsyncComponent 期望的 `AsyncComponentLoader<Component>` 签名。
 */
const AsyncPlugin = computed<Component | null>(() => {
  const loader = resolveComponent(props.pluginId)
  if (!loader) return null
  return defineAsyncComponent(async () => {
    const mod = await loader()
    return mod.default
  })
})

const hasComponent = computed(() => AsyncPlugin.value !== null)
</script>

<template>
  <d-card
    class="tool-card"
    :class="{ 'is-loading': loading }"
    shadow="never"
  >
    <template #title>
      <div class="tool-card__title">
        <span v-if="icon" class="tool-card__icon">{{ icon }}</span>
        <span class="tool-card__name" :title="title">{{ title }}</span>
      </div>
    </template>

    <template #content>
      <div class="tool-card__body">
        <div v-if="loading" class="tool-card__placeholder">
          加载中…
        </div>
        <div v-else-if="!hasComponent" class="tool-card__placeholder">
          该工具尚未实现（{{ pluginId }}）
        </div>
        <Suspense v-else>
          <component :is="AsyncPlugin" />
          <template #fallback>
            <div class="tool-card__placeholder">正在加载组件…</div>
          </template>
        </Suspense>
      </div>
    </template>
  </d-card>
</template>

<style scoped>
.tool-card {
  width: 100%;
  height: 100%;
  border: none;
  box-shadow: none;
  background: transparent;
}

.tool-card :deep(.devui-card) {
  border: none;
  box-shadow: none;
  background: transparent;
  height: 100%;
}

.tool-card__title {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 600;
  color: #1c1f23;
}

.tool-card__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 18px;
  height: 18px;
  font-size: 11px;
  color: #1668dc;
  background: #e8f3ff;
  border-radius: 4px;
  flex-shrink: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.tool-card__name {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.tool-card__body {
  height: 100%;
  min-height: 60px;
  display: flex;
  flex-direction: column;
  overflow: auto;
}

.tool-card__placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
  min-height: 60px;
  color: #86909c;
  font-size: 13px;
  padding: 12px;
  text-align: center;
}
</style>
