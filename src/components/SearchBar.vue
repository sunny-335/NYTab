<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { debounce } from 'lodash-es'
import { useSearchStore } from '@/stores/search.store'
import { useSearchEngines, buildSearchUrl } from '@/composables/useSearchEngines'
import { useBookmarkStore } from '@/stores/bookmark.store'
import type { Bookmark } from '@/api/bookmark.api'

/**
 * SearchBar — 搜索栏。
 *
 * 布局:[引擎图标按钮] [输入框] [提交按钮],输入框下方为建议下拉。
 *
 * 两个独立的下拉:
 *  1. 引擎切换下拉:点击左侧引擎图标按钮展开,列出 6 个内置引擎。
 *  2. 建议下拉:输入框聚焦且有内容时显示,根据 searchMode 渲染:
 *     - mixed      → 上方书签结果(最多 5 条)+ 下方搜索联想(8 条)
 *     - suggestions→ 仅搜索联想
 *     - bookmarks  → 仅书签结果
 *
 * 行为:
 *  - 输入防抖 300ms 后请求书签过滤与 Bing 联想 API;
 *  - 键盘 ↑/↓ 在建议项间导航,回车确认(选中项或直接搜索);
 *  - 失焦后延迟 150ms 隐藏建议下拉,避免误触;
 *  - "显示更多 N 条" 按钮可展开全部书签结果。
 */
const searchStore = useSearchStore()
const bookmarkStore = useBookmarkStore()
const { engines } = useSearchEngines()

/* ----------------------------- 输入与状态 ----------------------------- */
const query = ref('')
const inputComp = ref<{ focus?: () => void; $el?: HTMLElement } | null>(null)

const engineMenuOpen = ref(false)
const suggestionsOpen = ref(false)
const bookmarksExpanded = ref(false)

/** 书签结果(客户端过滤,匹配 title/url/tags)。 */
const bookmarkResults = ref<Bookmark[]>([])
/** Bing 联想结果。 */
const searchSuggestions = ref<string[]>([])
/** 联想请求是否在飞行中(用于空态展示)。 */
const suggestionsLoading = ref(false)

/** 建议下拉是否显示书签区块。 */
const showBookmarksSection = computed(
  () =>
    searchStore.searchMode === 'mixed' ||
    searchStore.searchMode === 'bookmarks',
)
/** 建议下拉是否显示搜索联想区块。 */
const showSuggestionsSection = computed(
  () =>
    searchStore.searchMode === 'mixed' ||
    searchStore.searchMode === 'suggestions',
)

/** 书签结果最多显示 5 条(未展开时)。 */
const BOOKMARK_LIMIT = 5
/** 搜索联想最多 8 条。 */
const SUGGESTION_LIMIT = 8

/** 当前可见的书签结果(展开后显示全部)。 */
const visibleBookmarks = computed<Bookmark[]>(() => {
  if (bookmarksExpanded.value) return bookmarkResults.value
  return bookmarkResults.value.slice(0, BOOKMARK_LIMIT)
})

/** 超出限制的书签数量(用于 "显示更多 N 条" 按钮)。 */
const extraBookmarkCount = computed(() =>
  Math.max(0, bookmarkResults.value.length - BOOKMARK_LIMIT),
)

/* ----------------------------- 键盘导航 ----------------------------- */
/**
 * 扁平化建议项,用于键盘 ↑/↓ 导航。
 * 顺序:书签结果 → "显示更多" 按钮(如有) → 搜索联想。
 */
interface NavItem {
  kind: 'bookmark' | 'more' | 'suggestion'
  bookmark?: Bookmark
  suggestion?: string
}

const navItems = computed<NavItem[]>(() => {
  const items: NavItem[] = []
  if (showBookmarksSection.value) {
    for (const b of visibleBookmarks.value) {
      items.push({ kind: 'bookmark', bookmark: b })
    }
    if (!bookmarksExpanded.value && extraBookmarkCount.value > 0) {
      items.push({ kind: 'more' })
    }
  }
  if (showSuggestionsSection.value) {
    for (const s of searchSuggestions.value) {
      items.push({ kind: 'suggestion', suggestion: s })
    }
  }
  return items
})

const activeIndex = ref(-1)

/** 当 navItems 变化时,把 activeIndex 限制在有效范围内。 */
watch(navItems, (items) => {
  if (activeIndex.value >= items.length) activeIndex.value = -1
})

/** 书签区块在 navItems 中的起始下标(始终为 0)。 */
const bookmarkNavOffset = computed(() => 0)
/** 联想区块在 navItems 中的起始下标 = 书签数量 + (more 按钮?1:0)。 */
const suggestionNavOffset = computed(() => {
  let n = visibleBookmarks.value.length
  if (
    showBookmarksSection.value &&
    !bookmarksExpanded.value &&
    extraBookmarkCount.value > 0
  ) {
    n += 1
  }
  return n
})

/* ----------------------------- 联想请求 ----------------------------- */
async function fetchSuggestions(q: string): Promise<string[]> {
  try {
    const res = await fetch(
      `https://api.bing.com/osjson.aspx?query=${encodeURIComponent(q)}`,
    )
    const data = (await res.json()) as unknown[]
    return (Array.isArray(data) && Array.isArray(data[1])
      ? (data[1] as string[])
      : []
    ).slice(0, SUGGESTION_LIMIT)
  } catch {
    return []
  }
}

/** 客户端书签过滤:匹配 title / url / tags(忽略大小写)。 */
function filterBookmarks(q: string): Bookmark[] {
  const kw = q.trim().toLowerCase()
  if (!kw) return []
  return bookmarkStore.bookmarks.filter((b) => {
    if (b.title?.toLowerCase().includes(kw)) return true
    if (b.url?.toLowerCase().includes(kw)) return true
    const tags = b.extra?.tags
    if (Array.isArray(tags) && tags.some((t) => t.toLowerCase().includes(kw))) {
      return true
    }
    return false
  })
}

/** 防抖 300ms 后执行书签过滤 + Bing 联想请求。 */
const debouncedRefresh = debounce(async (q: string) => {
  // 书签结果是同步过滤,直接计算;仅在需要书签区块时执行。
  bookmarkResults.value = showBookmarksSection.value ? filterBookmarks(q) : []

  // Bing 联想仅在需要联想区块且有关键字时请求。
  if (showSuggestionsSection.value && q.trim()) {
    suggestionsLoading.value = true
    try {
      searchSuggestions.value = await fetchSuggestions(q)
    } finally {
      suggestionsLoading.value = false
    }
  } else {
    searchSuggestions.value = []
  }

  // 重新计算 activeIndex 范围
  if (activeIndex.value >= navItems.value.length) {
    activeIndex.value = -1
  }
}, 300)

/* ----------------------------- 输入事件 ----------------------------- */
function onInput(value: string): void {
  query.value = value
  bookmarksExpanded.value = false
  activeIndex.value = -1
  if (!value.trim()) {
    // 清空时立即清掉结果,不等待防抖
    bookmarkResults.value = []
    searchSuggestions.value = []
    suggestionsOpen.value = false
    return
  }
  suggestionsOpen.value = true
  void debouncedRefresh(value)
}

function onFocus(): void {
  if (query.value.trim()) {
    suggestionsOpen.value = true
  }
}

function onBlur(): void {
  // 延迟 150ms 隐藏,给点击建议项留出时间
  window.setTimeout(() => {
    suggestionsOpen.value = false
  }, 150)
}

/* ----------------------------- 键盘事件 ----------------------------- */
function onKeydown(e: KeyboardEvent): void {
  // 引擎切换菜单打开时不拦截键盘
  if (engineMenuOpen.value) return

  if (e.key === 'ArrowDown') {
    if (!navItems.value.length) return
    e.preventDefault()
    if (!suggestionsOpen.value) suggestionsOpen.value = true
    activeIndex.value =
      activeIndex.value < navItems.value.length - 1
        ? activeIndex.value + 1
        : 0
  } else if (e.key === 'ArrowUp') {
    if (!navItems.value.length) return
    e.preventDefault()
    activeIndex.value =
      activeIndex.value > 0
        ? activeIndex.value - 1
        : navItems.value.length - 1
  } else if (e.key === 'Enter') {
    e.preventDefault()
    handleEnter()
  } else if (e.key === 'Escape') {
    suggestionsOpen.value = false
    activeIndex.value = -1
  }
}

/** 回车确认:有选中项则执行该项,否则直接提交搜索。 */
function handleEnter(): void {
  const item = navItems.value[activeIndex.value]
  if (!item) {
    submitSearch()
    return
  }
  if (item.kind === 'bookmark' && item.bookmark) {
    openBookmark(item.bookmark)
  } else if (item.kind === 'suggestion' && item.suggestion) {
    query.value = item.suggestion
    submitSearch()
  } else if (item.kind === 'more') {
    bookmarksExpanded.value = true
    activeIndex.value = -1
  }
}

/* ----------------------------- 提交与跳转 ----------------------------- */
function submitSearch(): void {
  const q = query.value.trim()
  if (!q) return
  const url = buildSearchUrl(searchStore.currentEngine, q)
  window.open(url, '_blank', 'noopener,noreferrer')
  suggestionsOpen.value = false
}

function openBookmark(b: Bookmark): void {
  if (!b.url || !/^https?:\/\//i.test(b.url)) return
  window.open(b.url, '_blank', 'noopener,noreferrer')
  suggestionsOpen.value = false
}

/* ----------------------------- 引擎切换 ----------------------------- */
function toggleEngineMenu(): void {
  engineMenuOpen.value = !engineMenuOpen.value
}

function selectEngine(id: string): void {
  searchStore.setEngine(id)
  engineMenuOpen.value = false
  // 切换后把焦点还给输入框,便于继续输入
  void nextTick(() => focusInput())
}

function focusInput(): void {
  const comp = inputComp.value
  if (!comp) return
  if (typeof comp.focus === 'function') {
    comp.focus()
    return
  }
  // 兜底:直接操作底层 native input
  const el = comp.$el as HTMLElement | undefined
  el?.querySelector('input')?.focus()
}

/* ----------------------------- 点击外部关闭引擎菜单 ----------------------------- */
function onClickOutside(e: MouseEvent): void {
  const target = e.target as HTMLElement
  if (!target.closest('.search-bar__engine')) {
    engineMenuOpen.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', onClickOutside)
  // 若 store 尚未初始化(由调用方负责 init),这里兜底调用一次。
  searchStore.init()
})
onUnmounted(() => {
  document.removeEventListener('click', onClickOutside)
  debouncedRefresh.cancel()
})
</script>

<template>
  <div class="search-bar">
    <!-- 引擎图标按钮 -->
    <div class="search-bar__engine">
      <button
        type="button"
        class="engine-btn"
        :title="searchStore.currentEngine.name"
        @click="toggleEngineMenu"
      >
        <span class="engine-btn__icon">{{ searchStore.currentEngine.icon }}</span>
        <span class="engine-btn__caret">▾</span>
      </button>
      <div v-if="engineMenuOpen" class="engine-menu" role="menu">
        <button
          v-for="engine in engines"
          :key="engine.id"
          type="button"
          class="engine-menu__item"
          :class="{ 'is-active': engine.id === searchStore.currentEngine.id }"
          role="menuitem"
          @click="selectEngine(engine.id)"
        >
          <span class="engine-menu__icon">{{ engine.icon }}</span>
          <span class="engine-menu__name">{{ engine.name }}</span>
        </button>
      </div>
    </div>

    <!-- 输入框 -->
    <d-input
      ref="inputComp"
      v-model="query"
      class="search-bar__input"
      placeholder="搜索或输入书签名/URL/标签…"
      @update:model-value="onInput"
      @focus="onFocus"
      @blur="onBlur"
      @keydown="onKeydown"
    />

    <!-- 提交按钮 -->
    <d-button type="primary" class="search-bar__submit" @click="submitSearch">
      搜索
    </d-button>

    <!-- 建议下拉 -->
    <div
      v-if="suggestionsOpen && (query.trim() || navItems.length)"
      class="suggestions"
    >
      <!-- 空态:仅当联想在加载且无任何结果时 -->
      <div
        v-if="!navItems.length && suggestionsLoading"
        class="suggestions__empty"
      >
        加载中…
      </div>

      <!-- 书签结果 -->
      <div
        v-if="showBookmarksSection && (visibleBookmarks.length || query.trim())"
        class="suggestions__section"
      >
        <div class="suggestions__title">书签</div>
        <button
          v-for="(b, i) in visibleBookmarks"
          :key="`bm-${b.id}`"
          type="button"
          class="suggestion-item"
          :class="{ 'is-active': activeIndex === bookmarkNavOffset + i }"
          @click="openBookmark(b)"
          @mouseenter="activeIndex = bookmarkNavOffset + i"
        >
          <span class="suggestion-item__icon">
            <img
              v-if="b.icon_url"
              :src="b.icon_url"
              :alt="b.title"
              referrerpolicy="no-referrer"
              @error="(e: Event) => ((e.target as HTMLImageElement).style.display = 'none')"
            />
            <span v-else>{{ (b.title || b.url || '?').charAt(0).toUpperCase() }}</span>
          </span>
          <span class="suggestion-item__main">
            <span class="suggestion-item__title" :title="b.title">{{ b.title }}</span>
            <span class="suggestion-item__sub" :title="b.url">{{ b.url }}</span>
          </span>
        </button>
        <button
          v-if="!bookmarksExpanded && extraBookmarkCount > 0"
          type="button"
          class="suggestion-item suggestion-item--more"
          :class="{
            'is-active':
              activeIndex === bookmarkNavOffset + visibleBookmarks.length,
          }"
          @click="bookmarksExpanded = true"
          @mouseenter="activeIndex = bookmarkNavOffset + visibleBookmarks.length"
        >
          显示更多 {{ extraBookmarkCount }} 条
        </button>
        <div
          v-if="!visibleBookmarks.length && query.trim()"
          class="suggestions__empty"
        >
          未匹配到书签
        </div>
      </div>

      <!-- 搜索联想 -->
      <div
        v-if="showSuggestionsSection && searchSuggestions.length"
        class="suggestions__section"
      >
        <div class="suggestions__title">搜索联想</div>
        <button
          v-for="(s, i) in searchSuggestions"
          :key="`sg-${i}`"
          type="button"
          class="suggestion-item"
          :class="{ 'is-active': activeIndex === suggestionNavOffset + i }"
          @click="() => { query = s; submitSearch() }"
          @mouseenter="activeIndex = suggestionNavOffset + i"
        >
          <span class="suggestion-item__icon suggestion-item__icon--text">🔍</span>
          <span class="suggestion-item__main">
            <span class="suggestion-item__title">{{ s }}</span>
          </span>
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.search-bar {
  position: relative;
  display: flex;
  align-items: stretch;
  gap: 8px;
  width: 100%;
  max-width: 720px;
  margin: 0 auto;
}

/* ----------------------------- 引擎按钮 ----------------------------- */
.search-bar__engine {
  position: relative;
  flex-shrink: 0;
}

.engine-btn {
  display: flex;
  align-items: center;
  gap: 4px;
  height: 100%;
  min-width: 56px;
  padding: 0 12px;
  background: #fff;
  border: 1px solid #e5e6eb;
  border-radius: 6px;
  cursor: pointer;
  font-size: 14px;
  color: #1c1f23;
  transition: border-color 0.15s, color 0.15s;
}

.engine-btn:hover {
  border-color: #1668dc;
  color: #1668dc;
}

.engine-btn__icon {
  font-size: 16px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
}

.engine-btn__caret {
  font-size: 10px;
  color: #86909c;
}

.engine-menu {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  min-width: 160px;
  background: #fff;
  border: 1px solid #e5e6eb;
  border-radius: 6px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
  z-index: 50;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.engine-menu__item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 14px;
  background: none;
  border: none;
  cursor: pointer;
  font-size: 14px;
  color: #4e5969;
  text-align: left;
  width: 100%;
  transition: background 0.15s, color 0.15s;
}

.engine-menu__item:hover {
  background: #f2f3f5;
  color: #1668dc;
}

.engine-menu__item.is-active {
  color: #1668dc;
  background: #e8f3ff;
}

.engine-menu__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  font-size: 14px;
  font-weight: 700;
  background: #f2f3f5;
  border-radius: 4px;
}

.engine-menu__item.is-active .engine-menu__icon {
  background: #1668dc;
  color: #fff;
}

/* ----------------------------- 输入框 ----------------------------- */
.search-bar__input {
  flex: 1;
  min-width: 0;
}

.search-bar__submit {
  flex-shrink: 0;
}

/* ----------------------------- 建议下拉 ----------------------------- */
.suggestions {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  right: 0;
  background: #fff;
  border: 1px solid #e5e6eb;
  border-radius: 6px;
  box-shadow: 0 6px 18px rgba(0, 0, 0, 0.1);
  z-index: 40;
  max-height: 60vh;
  overflow-y: auto;
  padding: 4px 0;
}

.suggestions__section + .suggestions__section {
  border-top: 1px solid #f2f3f5;
  margin-top: 4px;
  padding-top: 4px;
}

.suggestions__title {
  font-size: 12px;
  color: #86909c;
  font-weight: 500;
  padding: 6px 14px 2px;
}

.suggestions__empty {
  font-size: 13px;
  color: #86909c;
  padding: 10px 14px;
}

.suggestion-item {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 8px 14px;
  background: none;
  border: none;
  cursor: pointer;
  text-align: left;
  font-size: 14px;
  color: #1c1f23;
  transition: background 0.12s;
}

.suggestion-item:hover,
.suggestion-item.is-active {
  background: #f2f7ff;
}

.suggestion-item__icon {
  flex-shrink: 0;
  width: 24px;
  height: 24px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: #e8f3ff;
  color: #1668dc;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 700;
  overflow: hidden;
}

.suggestion-item__icon img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.suggestion-item__icon--text {
  background: transparent;
  font-size: 14px;
}

.suggestion-item__main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.suggestion-item__title {
  font-size: 14px;
  color: #1c1f23;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.suggestion-item__sub {
  font-size: 12px;
  color: #86909c;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.suggestion-item--more {
  justify-content: center;
  color: #1668dc;
  font-size: 13px;
  padding: 6px 14px;
}
</style>
