<script setup lang="ts">
import { ref, computed, onUnmounted, toRef } from 'vue'
import type { Ref, CSSProperties } from 'vue'
import { useGridLayout } from '@/composables/useGridLayout'
import type { LayoutItem, WorkspaceSettings } from '@/types/workspace'

/**
 * WorkspaceGrid — CSS Grid 容器，支持原生拖拽与缩放。
 *
 * 设计说明：
 * - vue-devui 的 `d-grid`（DRow/DCol）仅用于静态行列布局，不支持拖拽，
 *   因此本组件采用原生 CSS Grid + mousedown/mousemove/mouseup 实现。
 * - 拖拽期间使用本地 `dragState` 提供实时视觉反馈，不直接修改 prop。
 *   mouseup 时 emit 事件，由父组件（store）持久化。
 * - 移动端（effectiveCols === 1）强制单列堆叠，禁用拖拽。
 */

type DragType = 'move' | 'resize'

interface DragState {
  pluginId: string
  type: DragType
  startMouseX: number
  startMouseY: number
  origX: number
  origY: number
  origW: number
  origH: number
  x: number
  y: number
  w: number
  h: number
}

const props = defineProps<{
  layout: LayoutItem[]
  settings: WorkspaceSettings
  editable: boolean
}>()

const emit = defineEmits<{
  (e: 'move', pluginId: string, x: number, y: number): void
  (e: 'resize', pluginId: string, w: number, h: number): void
  (e: 'remove', pluginId: string): void
}>()

const containerRef = ref<HTMLElement | null>(null)
const dragState = ref<DragState | null>(null)

// useGridLayout 仅用于响应式断点 + effectiveCols（移动端单列）。
// toRef(props, ...) 返回 readonly ref，这里断言为可写 ref 以匹配 hook 签名；
// hook 内部仅读取 settings.cols，不会写入 layout/settings。
const { effectiveCols, cleanup } = useGridLayout({
  layout: toRef(props, 'layout') as unknown as Ref<LayoutItem[]>,
  settings: toRef(props, 'settings') as unknown as Ref<WorkspaceSettings>,
})

const isMobile = computed(() => effectiveCols.value === 1)

const gridStyle = computed<CSSProperties>(() => {
  if (isMobile.value) {
    return {
      gridTemplateColumns: '1fr',
      gridAutoRows: 'auto',
      gap: `${props.settings.gap}px`,
    }
  }
  return {
    gridTemplateColumns: `repeat(${effectiveCols.value}, 1fr)`,
    gridAutoRows: `${props.settings.rowHeight}px`,
    gap: `${props.settings.gap}px`,
  }
})

function getItemStyle(item: LayoutItem): CSSProperties {
  if (isMobile.value) {
    return {
      gridColumn: '1 / -1',
      gridRow: 'auto',
    }
  }
  const ds = dragState.value
  if (ds?.pluginId === item.pluginId) {
    return {
      gridColumn: `${ds.x + 1} / span ${ds.w}`,
      gridRow: `${ds.y + 1} / span ${ds.h}`,
    }
  }
  return {
    gridColumn: `${item.x + 1} / span ${item.w}`,
    gridRow: `${item.y + 1} / span ${item.h}`,
  }
}

function getCellSize() {
  if (!containerRef.value) return { width: 0, height: 0 }
  const rect = containerRef.value.getBoundingClientRect()
  const cols = effectiveCols.value
  const gap = props.settings.gap
  const cellWidth = cols > 0 ? (rect.width - (cols - 1) * gap) / cols : 0
  return { width: cellWidth, height: props.settings.rowHeight }
}

function startDrag(e: MouseEvent, item: LayoutItem, type: DragType) {
  if (!props.editable || isMobile.value) return
  e.preventDefault()
  if (type === 'resize') e.stopPropagation()
  dragState.value = {
    pluginId: item.pluginId,
    type,
    startMouseX: e.clientX,
    startMouseY: e.clientY,
    origX: item.x,
    origY: item.y,
    origW: item.w,
    origH: item.h,
    x: item.x,
    y: item.y,
    w: item.w,
    h: item.h,
  }
  document.addEventListener('mousemove', onMouseMove)
  document.addEventListener('mouseup', onMouseUp)
}

function onDragStart(e: MouseEvent, item: LayoutItem) {
  startDrag(e, item, 'move')
}

function onResizeStart(e: MouseEvent, item: LayoutItem) {
  startDrag(e, item, 'resize')
}

function onMouseMove(e: MouseEvent) {
  const ds = dragState.value
  if (!ds) return
  const { width: cellW, height: cellH } = getCellSize()
  const gap = props.settings.gap
  const dx = e.clientX - ds.startMouseX
  const dy = e.clientY - ds.startMouseY
  const deltaCellsX = Math.round(dx / (cellW + gap))
  const deltaCellsY = Math.round(dy / (cellH + gap))
  if (ds.type === 'move') {
    ds.x = Math.max(0, ds.origX + deltaCellsX)
    ds.y = Math.max(0, ds.origY + deltaCellsY)
  } else {
    ds.w = Math.max(1, ds.origW + deltaCellsX)
    ds.h = Math.max(1, ds.origH + deltaCellsY)
  }
}

function onMouseUp() {
  const ds = dragState.value
  if (ds) {
    if (ds.type === 'move') {
      emit('move', ds.pluginId, ds.x, ds.y)
    } else {
      emit('resize', ds.pluginId, ds.w, ds.h)
    }
  }
  dragState.value = null
  document.removeEventListener('mousemove', onMouseMove)
  document.removeEventListener('mouseup', onMouseUp)
}

onUnmounted(() => {
  document.removeEventListener('mousemove', onMouseMove)
  document.removeEventListener('mouseup', onMouseUp)
  dragState.value = null
  cleanup()
})
</script>

<template>
  <div
    ref="containerRef"
    class="workspace-grid"
    :style="gridStyle"
  >
    <div
      v-for="item in layout"
      :key="item.pluginId"
      class="workspace-grid__item"
      :class="{
        'is-dragging':
          dragState?.pluginId === item.pluginId && dragState?.type === 'move',
        'is-resizing':
          dragState?.pluginId === item.pluginId && dragState?.type === 'resize',
        'is-editable': editable && !isMobile,
      }"
      :style="getItemStyle(item)"
    >
      <div
        v-if="editable && !isMobile"
        class="workspace-grid__header"
        @mousedown="onDragStart($event, item)"
      >
        <span class="workspace-grid__drag-icon">⋮⋮</span>
        <button
          class="workspace-grid__remove"
          title="移除"
          @mousedown.stop
          @click="emit('remove', item.pluginId)"
        >
          ✕
        </button>
      </div>

      <div class="workspace-grid__content">
        <slot name="item" :item="item" />
      </div>

      <div
        v-if="editable && !isMobile"
        class="workspace-grid__resize-handle"
        title="拖拽调整大小"
        @mousedown="onResizeStart($event, item)"
      ></div>
    </div>
  </div>
</template>

<style scoped>
.workspace-grid {
  display: grid;
  width: 100%;
  min-height: 120px;
}

.workspace-grid__item {
  position: relative;
  background: #fff;
  border: 1px solid #e5e6eb;
  border-radius: 8px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  min-width: 0;
  min-height: 0;
  transition: box-shadow 0.2s;
}

.workspace-grid__item.is-editable:hover {
  box-shadow: 0 2px 12px rgba(22, 104, 220, 0.12);
}

.workspace-grid__item.is-dragging,
.workspace-grid__item.is-resizing {
  z-index: 20;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18);
  opacity: 0.92;
  cursor: move;
}

.workspace-grid__item.is-resizing {
  cursor: nwse-resize;
}

.workspace-grid__header {
  height: 28px;
  flex-shrink: 0;
  background: #f2f3f5;
  border-bottom: 1px solid #e5e6eb;
  cursor: move;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 6px 0 10px;
  user-select: none;
}

.workspace-grid__drag-icon {
  font-size: 14px;
  color: #86909c;
  letter-spacing: -2px;
  line-height: 1;
}

.workspace-grid__remove {
  width: 18px;
  height: 18px;
  border: none;
  background: transparent;
  color: #86909c;
  cursor: pointer;
  font-size: 12px;
  line-height: 1;
  padding: 0;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.15s;
}

.workspace-grid__remove:hover {
  background: #f53f3f;
  color: #fff;
}

.workspace-grid__content {
  flex: 1;
  overflow: auto;
  min-height: 0;
}

.workspace-grid__resize-handle {
  position: absolute;
  bottom: 0;
  right: 0;
  width: 18px;
  height: 18px;
  cursor: nwse-resize;
  z-index: 3;
}

.workspace-grid__resize-handle::after {
  content: '';
  position: absolute;
  bottom: 3px;
  right: 3px;
  width: 8px;
  height: 8px;
  border-right: 2px solid #86909c;
  border-bottom: 2px solid #86909c;
}

@media (max-width: 767px) {
  .workspace-grid__item {
    min-height: auto;
  }
}
</style>
