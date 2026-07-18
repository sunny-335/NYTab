<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useWorkspaceStore } from '@/stores/workspace.store'
import WorkspaceGrid from '@/components/workspace/WorkspaceGrid.vue'
import ToolCard from '@/components/workspace/ToolCard.vue'
import ToolPicker from '@/components/workspace/ToolPicker.vue'
import SearchBar from '@/components/SearchBar.vue'
import type { ToolPluginMeta } from '@/types/plugin'

/**
 * Home — 工作台首页。
 *
 * 组合三个子组件（WorkspaceGrid / ToolCard / ToolPicker）与 workspace store，
 * 提供工具栏（添加工具 / 编辑布局 / 重置布局）、加载态、空态与错误态。
 */
const store = useWorkspaceStore()

const editable = ref(false)
const pickerVisible = ref(false)

/** pluginId → meta 的查找表（用于 ToolCard 的 title / icon 显示）。 */
const metaMap = computed(() => {
  const map = new Map<string, ToolPluginMeta>()
  for (const p of store.availablePlugins) {
    map.set(p.pluginId, p)
  }
  return map
})

/** 当前布局中已添加的所有 pluginId（用于 ToolPicker 的 switch 状态）。 */
const activePluginIds = computed(() =>
  store.layout.map((i) => i.pluginId),
)

const isEmpty = computed(
  () => !store.loading && store.enabledItems.length === 0,
)

function getMeta(pluginId: string): ToolPluginMeta | undefined {
  return metaMap.value.get(pluginId)
}

function onMove(pluginId: string, x: number, y: number): void {
  store.updateLayoutItem(pluginId, { x, y })
}

function onResize(pluginId: string, w: number, h: number): void {
  store.updateLayoutItem(pluginId, { w, h })
}

function onRemove(pluginId: string): void {
  store.removePlugin(pluginId)
}

function onTogglePlugin(pluginId: string, enabled: boolean): void {
  store.togglePlugin(pluginId, enabled)
}

function onResetLayout(): void {
  if (window.confirm('确定要清空当前布局吗？此操作不可撤销。')) {
    store.resetLayout()
    editable.value = false
  }
}

function toggleEditable(): void {
  editable.value = !editable.value
}

onMounted(() => {
  store.init()
})
</script>

<template>
  <div class="home-page">
    <!-- 搜索栏(顶部) -->
    <div class="home-search">
      <SearchBar />
    </div>

    <!-- Toolbar -->
    <div class="home-toolbar">
      <h1 class="home-toolbar__title">工作台</h1>
      <div class="home-toolbar__actions">
        <d-button type="common" @click="pickerVisible = true">
          添加工具
        </d-button>
        <d-button
          :type="editable ? 'primary' : 'common'"
          @click="toggleEditable"
        >
          {{ editable ? '完成编辑' : '编辑布局' }}
        </d-button>
        <d-button
          type="common"
          :disabled="store.enabledItems.length === 0"
          @click="onResetLayout"
        >
          重置布局
        </d-button>
      </div>
    </div>

    <!-- Saving indicator -->
    <div v-if="store.saving" class="home-saving-tip">正在保存…</div>
    <div v-if="store.error" class="home-error-tip">
      出错了：{{ store.error.message }}
    </div>

    <!-- Body -->
    <div class="home-body">
      <!-- Loading -->
      <div v-if="store.loading" class="home-loading">
        <div class="home-loading__spinner"></div>
        <p class="home-loading__text">正在加载工作台…</p>
      </div>

      <!-- Empty state -->
      <div v-else-if="isEmpty" class="home-empty">
        <div class="home-empty__icon">+</div>
        <p class="home-empty__title">还没有添加任何工具</p>
        <p class="home-empty__desc">
          点击「添加工具」按钮，从工具面板选择你需要的工具卡片。
        </p>
        <d-button type="primary" @click="pickerVisible = true">
          添加工具
        </d-button>
      </div>

      <!-- Grid -->
      <WorkspaceGrid
        v-else
        :layout="store.enabledItems"
        :settings="store.settings"
        :editable="editable"
        @move="onMove"
        @resize="onResize"
        @remove="onRemove"
      >
        <template #item="{ item }">
          <ToolCard
            :plugin-id="item.pluginId"
            :title="getMeta(item.pluginId)?.name || item.pluginId"
            :icon="getMeta(item.pluginId)?.icon"
          />
        </template>
      </WorkspaceGrid>
    </div>

    <!-- Tool Picker Modal -->
    <ToolPicker
      v-model:visible="pickerVisible"
      :plugins="store.availablePlugins"
      :active-plugin-ids="activePluginIds"
      @toggle="onTogglePlugin"
    />
  </div>
</template>

<style scoped>
.home-page {
  width: 100%;
  min-height: 100%;
  display: flex;
  flex-direction: column;
}

.home-search {
  margin-bottom: 16px;
}

.home-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 0 0 16px;
  flex-wrap: wrap;
}

.home-toolbar__title {
  margin: 0;
  font-size: 22px;
  font-weight: 600;
  color: #1c1f23;
}

.home-toolbar__actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.home-saving-tip,
.home-error-tip {
  font-size: 13px;
  padding: 6px 12px;
  border-radius: 6px;
  margin-bottom: 12px;
}

.home-saving-tip {
  color: #1668dc;
  background: #e8f3ff;
}

.home-error-tip {
  color: #f53f3f;
  background: #ffece8;
}

.home-body {
  flex: 1;
  min-height: 0;
}

.home-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 80px 0;
  gap: 16px;
}

.home-loading__spinner {
  width: 32px;
  height: 32px;
  border: 3px solid #e5e6eb;
  border-top-color: #1668dc;
  border-radius: 50%;
  animation: home-spin 0.8s linear infinite;
}

.home-loading__text {
  margin: 0;
  color: #86909c;
  font-size: 14px;
}

@keyframes home-spin {
  to {
    transform: rotate(360deg);
  }
}

.home-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 80px 24px;
  gap: 12px;
  text-align: center;
  background: #fff;
  border: 1px dashed #d9d9d9;
  border-radius: 8px;
}

.home-empty__icon {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: #e8f3ff;
  color: #1668dc;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28px;
  font-weight: 300;
}

.home-empty__title {
  margin: 8px 0 0;
  font-size: 16px;
  font-weight: 600;
  color: #1c1f23;
}

.home-empty__desc {
  margin: 0;
  font-size: 13px;
  color: #86909c;
  max-width: 360px;
  line-height: 1.6;
}
</style>
