<script setup lang="ts">
import { computed } from 'vue'
import type { Bookmark } from '@/api/bookmark.api'

/**
 * BookmarkCard — 书签卡片。
 *
 * 展示图标（无则取标题首字母作占位）、标题、URL（截断）、标签。
 * 点击卡片非按钮区域在新标签页打开 URL；右上角提供编辑/删除按钮。
 * `draggable="true"` 触发 drag-start / drag-end 事件以支持拖拽排序。
 */
const props = defineProps<{
  bookmark: Bookmark
}>()

const emit = defineEmits<{
  (e: 'edit', bookmark: Bookmark): void
  (e: 'delete', bookmark: Bookmark): void
  (e: 'drag-start', bookmark: Bookmark): void
  (e: 'drag-end', bookmark: Bookmark): void
}>()

/** 取 extra.tags 数组（可能为空）。 */
const tags = computed<string[]>(() => props.bookmark.extra?.tags ?? [])

/** 取标题首字符（兼容中英文）作图标占位。 */
const initial = computed(() => {
  const t = props.bookmark.title?.trim() || props.bookmark.url
  return t ? t.charAt(0).toUpperCase() : '?'
})

/** 缩略 URL：去掉 scheme，超过 36 字符截断。 */
const displayUrl = computed(() => {
  const u = props.bookmark.url || ''
  return u.replace(/^https?:\/\//, '').length > 36
    ? u.replace(/^https?:\/\//, '').slice(0, 36) + '…'
    : u.replace(/^https?:\/\//, '')
})

function openUrl() {
  const url = props.bookmark.url
  if (!url) return
  // 仅允许 http/https（与后端 Validator::isSafeUrl 保持一致）。
  if (!/^https?:\/\//i.test(url)) return
  window.open(url, '_blank', 'noopener,noreferrer')
}

function onEdit(e: MouseEvent) {
  e.stopPropagation()
  emit('edit', props.bookmark)
}

function onDelete(e: MouseEvent) {
  e.stopPropagation()
  emit('delete', props.bookmark)
}

function onDragStart() {
  emit('drag-start', props.bookmark)
}

function onDragEnd() {
  emit('drag-end', props.bookmark)
}
</script>

<template>
  <div
    class="bookmark-card"
    draggable="true"
    @click="openUrl"
    @dragstart="onDragStart"
    @dragend="onDragEnd"
  >
    <div class="bookmark-card__head">
      <div class="bookmark-card__icon">
        <img
          v-if="bookmark.icon_url"
          :src="bookmark.icon_url"
          :alt="bookmark.title"
          referrerpolicy="no-referrer"
          @error="(e: Event) => (e.target as HTMLImageElement).style.display = 'none'"
        />
        <span v-else class="bookmark-card__initial">{{ initial }}</span>
      </div>
      <div class="bookmark-card__actions">
        <button
          type="button"
          class="icon-btn"
          title="编辑"
          @click="onEdit"
        >
          ✎
        </button>
        <button
          type="button"
          class="icon-btn icon-btn--danger"
          title="删除"
          @click="onDelete"
        >
          ✕
        </button>
      </div>
    </div>

    <div class="bookmark-card__title" :title="bookmark.title">
      {{ bookmark.title }}
    </div>
    <div class="bookmark-card__url" :title="bookmark.url">{{ displayUrl }}</div>

    <div v-if="tags.length" class="bookmark-card__tags">
      <span v-for="t in tags" :key="t" class="tag">#{{ t }}</span>
    </div>
  </div>
</template>

<style scoped>
.bookmark-card {
  position: relative;
  background: #fff;
  border: 1px solid #e5e6eb;
  border-radius: 8px;
  padding: 14px;
  cursor: pointer;
  transition: box-shadow 0.15s, border-color 0.15s, transform 0.05s;
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-height: 120px;
  user-select: none;
}

.bookmark-card:hover {
  border-color: #1668dc;
  box-shadow: 0 4px 12px rgba(22, 104, 220, 0.08);
}

.bookmark-card:active {
  transform: scale(0.99);
}

.bookmark-card__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
}

.bookmark-card__icon {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  background: #e8f3ff;
  color: #1668dc;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  flex-shrink: 0;
}

.bookmark-card__icon img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.bookmark-card__initial {
  font-size: 16px;
  font-weight: 700;
}

.bookmark-card__actions {
  display: flex;
  gap: 4px;
  opacity: 0;
  transition: opacity 0.15s;
}

.bookmark-card:hover .bookmark-card__actions {
  opacity: 1;
}

.icon-btn {
  width: 24px;
  height: 24px;
  border: none;
  background: #f2f3f5;
  color: #4e5969;
  border-radius: 4px;
  cursor: pointer;
  font-size: 13px;
  line-height: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s, color 0.15s;
}

.icon-btn:hover {
  background: #1668dc;
  color: #fff;
}

.icon-btn--danger:hover {
  background: #f53f3f;
}

.bookmark-card__title {
  font-size: 14px;
  font-weight: 600;
  color: #1c1f23;
  line-height: 1.3;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.bookmark-card__url {
  font-size: 12px;
  color: #86909c;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.bookmark-card__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: auto;
}

.tag {
  font-size: 11px;
  color: #1668dc;
  background: #e8f3ff;
  padding: 2px 6px;
  border-radius: 4px;
  line-height: 1.4;
}
</style>
