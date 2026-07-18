import { ref, watch, onMounted, onUnmounted, nextTick, type Ref } from 'vue'
import { toolApi } from '@/api/tool.api'

interface UsePluginStateOptions {
  /** 首次使用、后端无 state 时的默认值 */
  defaultState?: () => Record<string, unknown>
  /** 防抖保存延迟 ms，默认 800ms */
  debounceMs?: number
}

/**
 * 通用工具状态读写 hook。
 *
 * 让插件组件一行代码即可获得：
 * - 响应式 state（onMounted 时自动从后端拉取，与 defaultState 合并）
 * - 深度 watch + 防抖自动保存到后端
 * - onUnmounted 时若有未保存的变更，立即同步保存
 *
 * 用法：
 *   const { state, save, patch } = usePluginState('notes', {
 *     defaultState: () => ({ items: [] as Note[] }),
 *   })
 */
export function usePluginState<
  T extends Record<string, unknown> = Record<string, unknown>,
>(
  pluginId: string,
  options: UsePluginStateOptions = {},
): {
  state: Ref<T | null>
  loading: Ref<boolean>
  saving: Ref<boolean>
  error: Ref<Error | null>
  /** 立即保存（取消挂起的防抖定时器）。 */
  save: () => Promise<void>
  /** 重新从后端拉取状态。 */
  reload: () => Promise<void>
  /** 浅合并部分字段到当前 state（会触发防抖保存）。 */
  patch: (partial: Partial<T>) => void
} {
  const state = ref<T | null>(null) as Ref<T | null>
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<Error | null>(null)

  let saveTimer: ReturnType<typeof setTimeout> | null = null
  /** 标记 reload 期间应跳过 watch 触发的自动保存，避免回写覆盖。 */
  let skipWatch = false

  async function reload(): Promise<void> {
    loading.value = true
    error.value = null
    skipWatch = true
    try {
      const res = await toolApi.getState(pluginId)
      const remoteState = (res.state ?? {}) as T
      const fallback = (options.defaultState?.() ?? {}) as T
      state.value = { ...fallback, ...remoteState }
      // 等待 watch 回调在 skipWatch=true 时被跳过，再恢复标志。
      // Vue watch 默认 flush:'pre'（微任务），若同步重置 skipWatch 会在
      // 回调触发前执行，导致 reload 后误触发一次冗余保存。
      await nextTick()
    } catch (e) {
      error.value = e as Error
      state.value = (options.defaultState?.() ?? {}) as T
      await nextTick()
    } finally {
      loading.value = false
      skipWatch = false
    }
  }

  async function persist(): Promise<void> {
    if (!state.value) return
    saving.value = true
    error.value = null
    try {
      await toolApi.saveState(pluginId, state.value)
    } catch (e) {
      error.value = e as Error
    } finally {
      saving.value = false
    }
  }

  function save(): Promise<void> {
    if (saveTimer) {
      clearTimeout(saveTimer)
      saveTimer = null
    }
    return persist()
  }

  function patch(partial: Partial<T>): void {
    if (!state.value) return
    state.value = { ...state.value, ...partial }
  }

  onMounted(reload)

  watch(
    state,
    () => {
      if (skipWatch) return
      if (saveTimer) clearTimeout(saveTimer)
      saveTimer = setTimeout(persist, options.debounceMs ?? 800)
    },
    { deep: true },
  )

  onUnmounted(() => {
    if (saveTimer) {
      clearTimeout(saveTimer)
      saveTimer = null
      // 卸载前若有挂起的变更，立即同步保存（不阻塞卸载流程）
      void persist()
    }
  })

  return { state, loading, saving, error, save, reload, patch }
}
