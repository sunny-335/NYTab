import { defineStore } from 'pinia'
import { ref } from 'vue'
import {
  DEFAULT_ENGINE,
  SEARCH_ENGINES,
  useSearchEngines,
} from '@/composables/useSearchEngines'
import type { SearchEngine } from '@/composables/useSearchEngines'

/**
 * 搜索引擎记忆策略。
 * - `restore`:每次启动恢复为默认引擎(Bing)
 * - `remember`:记住用户上次切换的引擎
 */
export type SearchStrategy = 'restore' | 'remember'

/**
 * 搜索栏建议下拉的模式。
 * - `mixed`:同时显示书签结果与搜索联想(上方书签,下方联想)
 * - `suggestions`:仅显示搜索联想
 * - `bookmarks`:仅显示书签结果
 */
export type SearchMode = 'mixed' | 'suggestions' | 'bookmarks'

/** localStorage keys。 */
const LS_ENGINE_ID_KEY = 'nytab_search_engine_id'
const LS_STRATEGY_KEY = 'nytab_search_strategy'
const LS_MODE_KEY = 'nytab_search_mode'

const VALID_STRATEGIES: SearchStrategy[] = ['restore', 'remember']
const VALID_MODES: SearchMode[] = ['mixed', 'suggestions', 'bookmarks']

/**
 * 搜索引擎状态 store。
 *
 * 持久化策略:
 * - `strategy` 与 `searchMode` 始终持久化(用户偏好);
 * - `currentEngine.id` 仅在 `strategy=remember` 时持久化,
 *   `strategy=restore` 时启动会强制重置为 Bing。
 *
 * 调用方需在应用启动时(如 App.vue onMounted 或 router guard)调用 `init()`
 * 以从 localStorage 恢复状态。
 */
export const useSearchStore = defineStore('search', () => {
  const { getEngine } = useSearchEngines()

  /* ----------------------------- State ----------------------------- */
  const currentEngine = ref<SearchEngine>({ ...DEFAULT_ENGINE })
  const strategy = ref<SearchStrategy>('restore')
  const searchMode = ref<SearchMode>('mixed')

  /* ----------------------------- Actions ----------------------------- */

  /** 从 localStorage 恢复状态。strategy=restore 时强制重置为 Bing。 */
  function init(): void {
    // strategy
    const savedStrategy = localStorage.getItem(LS_STRATEGY_KEY) as SearchStrategy | null
    if (savedStrategy && VALID_STRATEGIES.includes(savedStrategy)) {
      strategy.value = savedStrategy
    }

    // searchMode
    const savedMode = localStorage.getItem(LS_MODE_KEY) as SearchMode | null
    if (savedMode && VALID_MODES.includes(savedMode)) {
      searchMode.value = savedMode
    }

    // currentEngine:仅 remember 策略下从 localStorage 恢复;否则重置为 Bing。
    if (strategy.value === 'remember') {
      const savedId = localStorage.getItem(LS_ENGINE_ID_KEY)
      if (savedId && SEARCH_ENGINES.some((e) => e.id === savedId)) {
        currentEngine.value = { ...getEngine(savedId) }
      } else {
        currentEngine.value = { ...DEFAULT_ENGINE }
      }
    } else {
      currentEngine.value = { ...DEFAULT_ENGINE }
      // restore 策略下不保留之前的 engine id
      localStorage.removeItem(LS_ENGINE_ID_KEY)
    }
  }

  /**
   * 切换当前搜索引擎。
   * - `strategy=remember` 时同步把 id 写入 localStorage;
   * - `strategy=restore` 时仅修改内存(下次启动仍恢复为 Bing)。
   */
  function setEngine(id: string): void {
    const engine = getEngine(id)
    currentEngine.value = { ...engine }
    if (strategy.value === 'remember') {
      localStorage.setItem(LS_ENGINE_ID_KEY, engine.id)
    }
  }

  /**
   * 设置记忆策略。
   * - 切换到 `restore` 时清空已保存的 engine id(下次启动恢复 Bing);
   * - 切换到 `remember` 时把当前 engine id 落盘,以便下次启动恢复。
   */
  function setStrategy(next: SearchStrategy): void {
    if (!VALID_STRATEGIES.includes(next) || strategy.value === next) return
    strategy.value = next
    localStorage.setItem(LS_STRATEGY_KEY, next)
    if (next === 'restore') {
      localStorage.removeItem(LS_ENGINE_ID_KEY)
    } else {
      localStorage.setItem(LS_ENGINE_ID_KEY, currentEngine.value.id)
    }
  }

  /** 设置搜索模式,并立即持久化。 */
  function setSearchMode(mode: SearchMode): void {
    if (!VALID_MODES.includes(mode) || searchMode.value === mode) return
    searchMode.value = mode
    localStorage.setItem(LS_MODE_KEY, mode)
  }

  return {
    // state
    currentEngine,
    strategy,
    searchMode,
    // actions
    init,
    setEngine,
    setStrategy,
    setSearchMode,
  }
})
