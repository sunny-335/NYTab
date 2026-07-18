<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Message } from 'vue-devui'
import { useBookmarkStore } from '@/stores/bookmark.store'
import type { Bookmark, BookmarkCategory } from '@/api/bookmark.api'
import BookmarkCard from '@/components/bookmark/BookmarkCard.vue'
import BookmarkEditModal from '@/components/bookmark/BookmarkEditModal.vue'
import CategoryManageModal from '@/components/bookmark/CategoryManageModal.vue'

/**
 * Bookmarks — 书签管理主页。
 *
 * 左侧 240px 边栏：分类树（带缩进的扁平渲染），顶部「管理分类」按钮。
 * 右侧主区：顶部工具栏（搜索 + 新增），书签网格（响应式 4-6 列），空状态。
 *
 * 搜索：在 filteredBookmarks 基础上按 title/url 包含关键字过滤（客户端）。
 * 拖拽：HTML5 DnD，drop 时把目标卡片插入到拖动卡片位置，重新分配 sort_order
 * 并批量落库。
 */
const bookmarkStore = useBookmarkStore()

/* --------------------------- 搜索 --------------------------- */
const searchKeyword = ref('')

const displayedBookmarks = computed<Bookmark[]>(() => {
  const kw = searchKeyword.value.trim().toLowerCase()
  if (!kw) return bookmarkStore.filteredBookmarks
  return bookmarkStore.filteredBookmarks.filter((b) => {
    return (
      b.title.toLowerCase().includes(kw) ||
      b.url.toLowerCase().includes(kw)
    )
  })
})

/* --------------------------- 分类树扁平行 --------------------------- */
interface SidebarRow {
  category: BookmarkCategory
  depth: number
}

const sidebarRows = computed<SidebarRow[]>(() => {
  const out: SidebarRow[] = []
  const walk = (nodes: BookmarkCategory[], depth: number) => {
    for (const n of nodes) {
      out.push({ category: n, depth })
      if (n.children?.length) walk(n.children, depth + 1)
    }
  }
  walk(bookmarkStore.categoryTree, 0)
  return out
})

function isCategoryActive(id: number): boolean {
  return bookmarkStore.currentCategoryId === id
}

function isAllActive(): boolean {
  return bookmarkStore.currentCategoryId === null
}

async function selectAll() {
  await bookmarkStore.selectCategory(null)
}

async function selectCategory(id: number) {
  await bookmarkStore.selectCategory(id)
}

/* --------------------------- 弹窗 --------------------------- */
const editModalVisible = ref(false)
const editingBookmark = ref<Bookmark | null>(null)
const categoryModalVisible = ref(false)

function openCreate() {
  editingBookmark.value = null
  editModalVisible.value = true
}

function openEdit(b: Bookmark) {
  editingBookmark.value = b
  editModalVisible.value = true
}

async function handleDeleteBookmark(b: Bookmark) {
  if (!window.confirm(`确定删除书签「${b.title}」？`)) return
  try {
    await bookmarkStore.deleteBookmark(b.id)
    Message.success('书签已删除')
  } catch {
    // 拦截器已 toast
  }
}

/* --------------------------- 拖拽排序 --------------------------- */
const draggingId = ref<number | null>(null)

function onDragStart(b: Bookmark) {
  draggingId.value = b.id
}

function onDragEnd(_b: Bookmark) {
  draggingId.value = null
}

function onDragOver(e: DragEvent) {
  // 允许 drop
  if (e.dataTransfer) e.dataTransfer.dropEffect = 'move'
  e.preventDefault()
}

function onDrop(target: Bookmark) {
  const sourceId = draggingId.value
  draggingId.value = null
  if (sourceId === null || sourceId === target.id) return

  // 基于当前 displayedBookmarks 顺序重新分配 sort_order。
  const list = displayedBookmarks.value
  const fromIdx = list.findIndex((b) => b.id === sourceId)
  const toIdx = list.findIndex((b) => b.id === target.id)
  if (fromIdx === -1 || toIdx === -1) return

  // 构造新顺序：把 fromIdx 拿出来插到 toIdx 位置。
  const reordered = [...list]
  const [moved] = reordered.splice(fromIdx, 1)
  reordered.splice(toIdx, 0, moved)

  // 重新分配 sort_order（从 0 开始递增）。
  const items = reordered.map((b, idx) => ({ id: b.id, sort_order: idx }))
  void bookmarkStore.reorderBookmarks(items)
}

/* --------------------------- 初始化 --------------------------- */
onMounted(async () => {
  await Promise.all([
    bookmarkStore.fetchCategories(),
    bookmarkStore.fetchBookmarks(),
  ])
})
</script>

<template>
  <div class="bookmarks-page">
    <!-- 左侧分类边栏 -->
    <aside class="bookmarks-sidebar">
      <div class="sidebar-head">
        <span class="sidebar-title">分类</span>
        <button
          type="button"
          class="text-btn"
          title="管理分类"
          @click="categoryModalVisible = true"
        >
          管理
        </button>
      </div>
      <ul class="sidebar-list">
        <li
          class="sidebar-item"
          :class="{ active: isAllActive() }"
          @click="selectAll"
        >
          <span class="sidebar-item__icon">☆</span>
          <span class="sidebar-item__name">全部书签</span>
        </li>
        <li
          v-for="row in sidebarRows"
          :key="row.category.id"
          class="sidebar-item"
          :class="{ active: isCategoryActive(row.category.id) }"
          :style="{ paddingLeft: 12 + row.depth * 16 + 'px' }"
          :title="row.category.name"
          @click="selectCategory(row.category.id)"
        >
          <span class="sidebar-item__icon">📁</span>
          <span class="sidebar-item__name">{{ row.category.name }}</span>
        </li>
        <li v-if="sidebarRows.length === 0" class="sidebar-empty">
          暂无分类
        </li>
      </ul>
    </aside>

    <!-- 右侧主区 -->
    <section class="bookmarks-main">
      <div class="main-toolbar">
        <d-input
          v-model="searchKeyword"
          class="main-toolbar__search"
          placeholder="搜索书签标题或 URL…"
        />
        <d-button type="primary" @click="openCreate">+ 新增书签</d-button>
      </div>

      <div v-if="bookmarkStore.loading" class="main-state">
        加载中…
      </div>

      <div
        v-else-if="displayedBookmarks.length > 0"
        class="bookmark-grid"
      >
        <BookmarkCard
          v-for="b in displayedBookmarks"
          :key="b.id"
          :bookmark="b"
          @edit="openEdit"
          @delete="handleDeleteBookmark"
          @drag-start="onDragStart"
          @drag-end="onDragEnd"
          @dragover="onDragOver"
          @drop="onDrop(b)"
        />
      </div>

      <div v-else class="main-state main-state--empty">
        <p>暂无书签</p>
        <d-button type="primary" @click="openCreate">+ 新增书签</d-button>
      </div>
    </section>

    <!-- 弹窗 -->
    <BookmarkEditModal
      v-model:visible="editModalVisible"
      :bookmark="editingBookmark"
    />
    <CategoryManageModal
      v-model:visible="categoryModalVisible"
    />
  </div>
</template>

<style scoped>
.bookmarks-page {
  display: flex;
  gap: 20px;
  align-items: flex-start;
}

/* --------------------------- Sidebar --------------------------- */
.bookmarks-sidebar {
  width: 240px;
  flex-shrink: 0;
  background: #fff;
  border: 1px solid #e5e6eb;
  border-radius: 8px;
  padding: 12px 0;
  position: sticky;
  top: 80px;
}

.sidebar-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 12px 8px;
  border-bottom: 1px solid #f2f3f5;
}

.sidebar-title {
  font-size: 13px;
  font-weight: 600;
  color: #4e5969;
}

.text-btn {
  background: none;
  border: none;
  font-size: 12px;
  color: #1668dc;
  cursor: pointer;
  padding: 2px 4px;
  border-radius: 3px;
}

.text-btn:hover {
  background: #e8f3ff;
}

.sidebar-list {
  list-style: none;
  margin: 0;
  padding: 4px 0;
}

.sidebar-item {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 12px;
  cursor: pointer;
  font-size: 13px;
  color: #1c1f23;
  transition: background 0.15s, color 0.15s;
}

.sidebar-item:hover {
  background: #f2f3f5;
}

.sidebar-item.active {
  background: #e8f3ff;
  color: #1668dc;
  font-weight: 500;
}

.sidebar-item__icon {
  font-size: 12px;
  flex-shrink: 0;
}

.sidebar-item__name {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.sidebar-empty {
  padding: 16px 12px;
  font-size: 12px;
  color: #c9cdd4;
  text-align: center;
}

/* --------------------------- Main --------------------------- */
.bookmarks-main {
  flex: 1;
  min-width: 0;
}

.main-toolbar {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
}

.main-toolbar__search {
  max-width: 320px;
}

.bookmark-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 12px;
}

.main-state {
  padding: 60px 0;
  text-align: center;
  color: #86909c;
  font-size: 14px;
}

.main-state--empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}

/* --------------------------- 响应式 --------------------------- */
@media (max-width: 768px) {
  .bookmarks-page {
    flex-direction: column;
  }
  .bookmarks-sidebar {
    width: 100%;
    position: static;
  }
  .main-toolbar__search {
    flex: 1;
    max-width: none;
  }
}
</style>
