import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { bookmarkApi } from '@/api/bookmark.api'
import type {
  Bookmark,
  BookmarkCategory,
  BookmarkWritePayload,
  CategoryWritePayload,
} from '@/api/bookmark.api'

/**
 * 书签模块 Pinia store。
 *
 * 后端 `GET /bookmark-categories` 返回嵌套树（节点含 `children`），
 * 这里在 fetch 时把树压扁存入 `categories`，再由 `categoryTree` getter
 * 基于 `parent_id` 重建树。这样所有 mutation 都只需操作扁平数组。
 *
 * `fetchBookmarks` 直接把 `currentCategoryId` 透传给后端做服务端过滤；
 * `filteredBookmarks` getter 再做一次客户端兜底过滤，保证 UI 一致。
 */
export const useBookmarkStore = defineStore('bookmark', () => {
  /* ----------------------------- State ----------------------------- */
  const categories = ref<BookmarkCategory[]>([])
  const bookmarks = ref<Bookmark[]>([])
  /** null = 全部书签。 */
  const currentCategoryId = ref<number | null>(null)
  const loading = ref(false)
  const error = ref<Error | null>(null)

  /* ----------------------------- Getters ----------------------------- */
  /** 把扁平 categories 数组按 parent_id 重建为嵌套树。 */
  const categoryTree = computed<BookmarkCategory[]>(() => {
    const byParent = new Map<number | null, BookmarkCategory[]>()
    for (const c of categories.value) {
      const key = c.parent_id
      const arr = byParent.get(key) ?? []
      arr.push({ ...c, children: [] })
      byParent.set(key, arr)
    }
    const build = (parentId: number | null): BookmarkCategory[] => {
      const nodes = byParent.get(parentId) ?? []
      for (const n of nodes) {
        n.children = build(n.id)
      }
      return nodes
    }
    return build(null)
  })

  /**
   * 客户端兜底过滤。`fetchBookmarks` 已传 categoryId 给后端，
   * 但当 currentCategoryId 为 null（全部）时直接返回全部 bookmarks。
   */
  const filteredBookmarks = computed<Bookmark[]>(() => {
    if (currentCategoryId.value === null) return bookmarks.value
    return bookmarks.value.filter(
      (b) => b.category_id === currentCategoryId.value,
    )
  })

  /** 把树扁平化用于 d-select 选项展示（带缩进前缀）。 */
  const flatCategoryOptions = computed(() => {
    const out: Array<{ label: string; value: number | null }> = [
      { label: '未分类', value: null },
    ]
    const walk = (nodes: BookmarkCategory[], depth: number) => {
      for (const n of nodes) {
        const prefix = depth > 0 ? '　'.repeat(depth) + '└ ' : ''
        out.push({ label: prefix + n.name, value: n.id })
        if (n.children && n.children.length) {
          walk(n.children, depth + 1)
        }
      }
    }
    walk(categoryTree.value, 0)
    return out
  })

  /* ----------------------------- Actions ----------------------------- */
  /** 把后端返回的嵌套树压扁为 flat array（保留原节点字段，丢弃 children）。 */
  function flattenTree(tree: BookmarkCategory[]): BookmarkCategory[] {
    const out: BookmarkCategory[] = []
    const walk = (nodes: BookmarkCategory[]) => {
      for (const n of nodes) {
        const { children: _children, ...rest } = n
        out.push(rest)
        if (_children && _children.length) walk(_children)
      }
    }
    walk(tree)
    return out
  }

  async function fetchCategories() {
    loading.value = true
    error.value = null
    try {
      const tree = await bookmarkApi.listCategories()
      categories.value = flattenTree(tree ?? [])
    } catch (e) {
      error.value = e as Error
    } finally {
      loading.value = false
    }
  }

  async function fetchBookmarks() {
    loading.value = true
    error.value = null
    try {
      const list = await bookmarkApi.listBookmarks({
        categoryId: currentCategoryId.value ?? undefined,
      })
      bookmarks.value = list ?? []
    } catch (e) {
      error.value = e as Error
    } finally {
      loading.value = false
    }
  }

  /** 切换分类并重新拉取书签。 */
  async function selectCategory(id: number | null) {
    if (currentCategoryId.value === id) return
    currentCategoryId.value = id
    await fetchBookmarks()
  }

  async function createCategory(name: string, parentId: number | null = null) {
    const payload: CategoryWritePayload = { name, parent_id: parentId }
    await bookmarkApi.createCategory(payload)
    await fetchCategories()
  }

  async function updateCategory(id: number, data: CategoryWritePayload) {
    await bookmarkApi.updateCategory(id, data)
    await fetchCategories()
  }

  async function deleteCategory(id: number) {
    await bookmarkApi.deleteCategory(id)
    await fetchCategories()
    // 若删除的正是当前选中分类，回退到「全部」并刷新书签。
    if (currentCategoryId.value === id) {
      currentCategoryId.value = null
    }
    await fetchBookmarks()
  }

  async function createBookmark(data: BookmarkWritePayload) {
    await bookmarkApi.createBookmark(data)
    await fetchBookmarks()
  }

  async function updateBookmark(id: number, data: BookmarkWritePayload) {
    await bookmarkApi.updateBookmark(id, data)
    await fetchBookmarks()
  }

  async function deleteBookmark(id: number) {
    await bookmarkApi.deleteBookmark(id)
    await fetchBookmarks()
  }

  /**
   * 批量重排序。先在本地按新 sort_order 排好顺序，再异步落库；
   * 失败时回滚错误（具体重排由调用方维护 items 数组）。
   */
  async function reorderBookmarks(items: Array<{ id: number; sort_order: number }>) {
    // 局部更新 sort_order，避免刷新闪烁。
    const map = new Map(items.map((it) => [it.id, it.sort_order]))
    bookmarks.value = bookmarks.value
      .map((b) =>
        map.has(b.id) ? { ...b, sort_order: map.get(b.id)! } : b,
      )
      .sort((a, b) => a.sort_order - b.sort_order)

    try {
      await bookmarkApi.reorderBookmarks(items)
    } catch (e) {
      error.value = e as Error
      await fetchBookmarks()
    }
  }

  return {
    // state
    categories,
    bookmarks,
    currentCategoryId,
    loading,
    error,
    // getters
    categoryTree,
    filteredBookmarks,
    flatCategoryOptions,
    // actions
    fetchCategories,
    fetchBookmarks,
    selectCategory,
    createCategory,
    updateCategory,
    deleteCategory,
    createBookmark,
    updateBookmark,
    deleteBookmark,
    reorderBookmarks,
  }
})
