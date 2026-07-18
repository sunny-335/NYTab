<script setup lang="ts">
import { computed } from 'vue'
import type { ToolPluginMeta, ToolCategory } from '@/types/plugin'

/**
 * ToolPicker — 添加/移除工具面板。
 *
 * 使用 Vue DevUI 的 `d-modal` 弹窗，按 category 分组列出所有可用插件，
 * 每行配 `d-switch` 切换启用/禁用。
 *
 * 通过 `v-model:visible`（即 visible prop + update:visible emit）控制显隐，
 * 通过 `toggle` emit 通知父组件切换某个插件的启用状态。
 */
const props = defineProps<{
  visible: boolean
  /** 后端 registry 返回的全部插件清单。 */
  plugins: ToolPluginMeta[]
  /** 当前已添加到布局中的 pluginId 列表（无论 enabled 状态）。 */
  activePluginIds: string[]
}>()

const emit = defineEmits<{
  (e: 'update:visible', val: boolean): void
  (e: 'toggle', pluginId: string, enabled: boolean): void
}>()

const CATEGORY_LABELS: Record<ToolCategory, string> = {
  efficiency: '效率工具',
  developer: '开发者工具',
  lifestyle: '生活工具',
}

/** 按 category 分组（保持后端清单顺序）。 */
const grouped = computed(() => {
  const map = new Map<ToolCategory, ToolPluginMeta[]>()
  for (const p of props.plugins) {
    if (!map.has(p.category)) map.set(p.category, [])
    map.get(p.category)!.push(p)
  }
  return Array.from(map.entries())
})

function isActive(pluginId: string): boolean {
  return props.activePluginIds.includes(pluginId)
}

/** d-switch 的 modelValue 类型为 string | number | boolean，这里归一为 boolean。 */
function onSwitchChange(pluginId: string, val: string | number | boolean): void {
  emit('toggle', pluginId, !!val)
}

function close(): void {
  emit('update:visible', false)
}
</script>

<template>
  <d-modal
    :model-value="visible"
    title="添加工具"
    show-close
    show-overlay
    append-to-body
    @update:model-value="emit('update:visible', $event)"
  >
    <div class="tool-picker">
      <div
        v-for="[category, items] in grouped"
        :key="category"
        class="tool-picker__group"
      >
        <h4 class="tool-picker__group-title">
          {{ CATEGORY_LABELS[category] || category }}
        </h4>
        <div class="tool-picker__items">
          <div
            v-for="item in items"
            :key="item.pluginId"
            class="tool-picker__item"
          >
            <div class="tool-picker__item-info">
              <div class="tool-picker__item-name">{{ item.name }}</div>
              <div v-if="item.description" class="tool-picker__item-desc">
                {{ item.description }}
              </div>
            </div>
            <d-switch
              :model-value="isActive(item.pluginId)"
              @update:model-value="onSwitchChange(item.pluginId, $event)"
            />
          </div>
        </div>
      </div>
      <div v-if="plugins.length === 0" class="tool-picker__empty">
        暂无可用工具
      </div>
    </div>
    <template #footer>
      <d-button type="primary" @click="close">完成</d-button>
    </template>
  </d-modal>
</template>

<style scoped>
.tool-picker {
  min-width: 360px;
  max-width: 560px;
  max-height: 60vh;
  overflow-y: auto;
  padding: 4px 0;
}

.tool-picker__group + .tool-picker__group {
  margin-top: 20px;
}

.tool-picker__group-title {
  margin: 0 0 10px;
  font-size: 13px;
  font-weight: 600;
  color: #4e5969;
  padding-left: 4px;
}

.tool-picker__items {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.tool-picker__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 12px;
  border-radius: 6px;
  transition: background 0.15s;
}

.tool-picker__item:hover {
  background: #f7f8fa;
}

.tool-picker__item-info {
  flex: 1;
  min-width: 0;
}

.tool-picker__item-name {
  font-size: 14px;
  font-weight: 500;
  color: #1c1f23;
  line-height: 1.4;
}

.tool-picker__item-desc {
  font-size: 12px;
  color: #86909c;
  margin-top: 2px;
  line-height: 1.4;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.tool-picker__empty {
  text-align: center;
  color: #86909c;
  font-size: 14px;
  padding: 40px 0;
}
</style>
