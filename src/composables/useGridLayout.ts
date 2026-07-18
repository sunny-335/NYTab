import { ref, computed, type Ref } from 'vue'
import type { LayoutItem, WorkspaceSettings, Breakpoint } from '@/types/workspace'

interface UseGridLayoutOptions {
  layout: Ref<LayoutItem[]>
  settings: Ref<WorkspaceSettings>
  /** 布局变更回调（已做变更后触发，调用方可在此做防抖持久化）。 */
  onChange?: (layout: LayoutItem[]) => void
}

/**
 * 工作台网格布局 hook。
 *
 * 负责：
 * - 监听窗口尺寸切换断点（mobile / tablet / desktop）
 * - 根据断点计算有效列数（移动端单列、平板 2 列、桌面按 settings.cols）
 * - 提供布局项的增删改查操作（移动 / 缩放 / 移除 / 新增 / 开关）
 *
 * 注意：本 hook 不自动注册 onUnmounted 清理，调用方需在适当时机调用 cleanup()。
 */
export function useGridLayout(options: UseGridLayoutOptions) {
  const breakpoint = ref<Breakpoint>('desktop')

  function detectBreakpoint(): void {
    const w = window.innerWidth
    if (w < 768) breakpoint.value = 'mobile'
    else if (w < 1024) breakpoint.value = 'tablet'
    else breakpoint.value = 'desktop'
  }

  if (typeof window !== 'undefined') {
    detectBreakpoint()
    window.addEventListener('resize', detectBreakpoint)
  }

  // 移动端：单列堆叠；平板：2 列；桌面：按 settings.cols
  const effectiveCols = computed(() => {
    if (breakpoint.value === 'mobile') return 1
    if (breakpoint.value === 'tablet') return 2
    return options.settings.value.cols
  })

  function moveItem(pluginId: string, x: number, y: number): void {
    const item = options.layout.value.find((i) => i.pluginId === pluginId)
    if (item) {
      item.x = Math.max(0, x)
      item.y = Math.max(0, y)
      options.onChange?.(options.layout.value)
    }
  }

  function resizeItem(pluginId: string, w: number, h: number): void {
    const item = options.layout.value.find((i) => i.pluginId === pluginId)
    if (item) {
      item.w = Math.max(1, w)
      item.h = Math.max(1, h)
      options.onChange?.(options.layout.value)
    }
  }

  function removeItem(pluginId: string): void {
    const idx = options.layout.value.findIndex((i) => i.pluginId === pluginId)
    if (idx >= 0) {
      options.layout.value.splice(idx, 1)
      options.onChange?.(options.layout.value)
    }
  }

  function addItem(item: LayoutItem): void {
    if (!options.layout.value.find((i) => i.pluginId === item.pluginId)) {
      options.layout.value.push(item)
      options.onChange?.(options.layout.value)
    }
  }

  function toggleItem(pluginId: string, enabled: boolean): void {
    const item = options.layout.value.find((i) => i.pluginId === pluginId)
    if (item) {
      item.enabled = enabled
      options.onChange?.(options.layout.value)
    }
  }

  function cleanup(): void {
    if (typeof window !== 'undefined') {
      window.removeEventListener('resize', detectBreakpoint)
    }
  }

  return {
    breakpoint,
    effectiveCols,
    moveItem,
    resizeItem,
    removeItem,
    addItem,
    toggleItem,
    cleanup,
  }
}
