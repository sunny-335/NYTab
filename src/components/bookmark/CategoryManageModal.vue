<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { Message } from 'vue-devui'
import { useBookmarkStore } from '@/stores/bookmark.store'
import type { BookmarkCategory } from '@/api/bookmark.api'

/**
 * CategoryManageModal — 分类管理弹窗。
 *
 * 树形分类以「带缩进的扁平列表」形式展示，每行提供编辑/删除按钮。
 * 顶部「新增分类」按钮展开内联表单（名称 + 父分类 select）。
 * 删除时弹 `confirm` 二次确认——后端会 ON DELETE CASCADE 子分类，
 * 并对引用此分类的书签 SET NULL（即归入未分类）。
 */
const props = defineProps<{
  visible: boolean
}>()

const emit = defineEmits<{
  (e: 'update:visible', val: boolean): void
  (e: 'changed'): void
}>()

const bookmarkStore = useBookmarkStore()

/** 扁平行（带 depth 缩进信息），便于在表格中渲染并就地编辑。 */
interface CategoryRow {
  category: BookmarkCategory
  depth: number
}

const flatRows = computed<CategoryRow[]>(() => {
  const out: CategoryRow[] = []
  const walk = (nodes: BookmarkCategory[], depth: number) => {
    for (const n of nodes) {
      out.push({ category: n, depth })
      if (n.children?.length) walk(n.children, depth + 1)
    }
  }
  walk(bookmarkStore.categoryTree, 0)
  return out
})

/* --------------------------- 新增分类表单 --------------------------- */
const showAddForm = ref(false)
const newName = ref('')
const newParentId = ref<number>(0) // 0 = 顶层

/** 父分类选项：[顶层, ...带缩进的现有分类]。 */
const parentOptions = computed(() => {
  const out: Array<{ value: number; name: string }> = [
    { value: 0, name: '（顶层）' },
  ]
  for (const row of flatRows.value) {
    const prefix = row.depth > 0 ? '　'.repeat(row.depth) + '└ ' : ''
    out.push({ value: row.category.id, name: prefix + row.category.name })
  }
  return out
})

const canAdd = computed(
  () => newName.value.trim() !== '' && !adding.value,
)

const adding = ref(false)

async function handleAdd() {
  if (!canAdd.value) return
  adding.value = true
  try {
    await bookmarkStore.createCategory(
      newName.value.trim(),
      newParentId.value === 0 ? null : newParentId.value,
    )
    Message.success('分类已添加')
    emit('changed')
    newName.value = ''
    newParentId.value = 0
    showAddForm.value = false
  } catch {
    // 拦截器已 toast
  } finally {
    adding.value = false
  }
}

function cancelAdd() {
  showAddForm.value = false
  newName.value = ''
  newParentId.value = 0
}

/* --------------------------- 内联编辑 --------------------------- */
const editingId = ref<number | null>(null)
const editingName = ref('')
const savingEdit = ref(false)

function startEdit(row: CategoryRow) {
  editingId.value = row.category.id
  editingName.value = row.category.name
}

function cancelEdit() {
  editingId.value = null
  editingName.value = ''
}

async function saveEdit() {
  if (editingId.value === null) return
  const name = editingName.value.trim()
  if (name === '') {
    Message.error('分类名称不能为空')
    return
  }
  savingEdit.value = true
  try {
    await bookmarkStore.updateCategory(editingId.value, { name })
    Message.success('分类已更新')
    emit('changed')
    cancelEdit()
  } catch {
    // 拦截器已 toast
  } finally {
    savingEdit.value = false
  }
}

/* --------------------------- 删除 --------------------------- */
async function handleDelete(row: CategoryRow) {
  const c = row.category
  const hasChildren = !!c.children?.length
  const msg = hasChildren
    ? `确定删除分类「${c.name}」？\n该分类及其子分类将被一并删除，下属书签将归入未分类。`
    : `确定删除分类「${c.name}」？\n下属书签将归入未分类。`
  if (!window.confirm(msg)) return

  try {
    await bookmarkStore.deleteCategory(c.id)
    Message.success('分类已删除')
    emit('changed')
  } catch {
    // 拦截器已 toast
  }
}

/* --------------------------- 弹窗生命周期 --------------------------- */
watch(
  () => props.visible,
  (open) => {
    if (open) {
      void bookmarkStore.fetchCategories()
      cancelAdd()
      cancelEdit()
    }
  },
)

function close() {
  emit('update:visible', false)
}
</script>

<template>
  <d-modal
    :model-value="visible"
    title="管理分类"
    show-close
    show-overlay
    append-to-body
    @update:model-value="emit('update:visible', $event)"
  >
    <div class="category-manage">
      <div class="category-manage__toolbar">
        <d-button
          v-if="!showAddForm"
          type="primary"
          size="small"
          @click="showAddForm = true"
        >
          + 新增分类
        </d-button>
      </div>

      <div v-if="showAddForm" class="add-form">
        <div class="add-form__row">
          <label class="form-field">
            <span class="form-label">名称</span>
            <d-input v-model="newName" placeholder="分类名称" />
          </label>
          <label class="form-field">
            <span class="form-label">父分类</span>
            <d-select
              v-model="newParentId"
              :options="parentOptions"
              placeholder="选择父分类"
            />
          </label>
        </div>
        <div class="add-form__actions">
          <d-button size="small" type="common" @click="cancelAdd">
            取消
          </d-button>
          <d-button
            size="small"
            type="primary"
            :loading="adding"
            :disabled="!canAdd"
            @click="handleAdd"
          >
            添加
          </d-button>
        </div>
      </div>

      <div class="category-list">
        <div
          v-for="row in flatRows"
          :key="row.category.id"
          class="category-row"
          :style="{ paddingLeft: 12 + row.depth * 20 + 'px' }"
        >
          <template v-if="editingId === row.category.id">
            <d-input
              v-model="editingName"
              size="sm"
              class="category-row__edit-input"
            />
            <d-button
              size="sm"
              type="primary"
              :loading="savingEdit"
              @click="saveEdit"
            >
              保存
            </d-button>
            <d-button size="sm" type="common" @click="cancelEdit">
              取消
            </d-button>
          </template>
          <template v-else>
            <span class="category-row__name" :title="row.category.name">
              {{ row.category.name }}
            </span>
            <div class="category-row__actions">
              <button
                type="button"
                class="text-btn"
                @click="startEdit(row)"
              >
                编辑
              </button>
              <button
                type="button"
                class="text-btn text-btn--danger"
                @click="handleDelete(row)"
              >
                删除
              </button>
            </div>
          </template>
        </div>
        <div v-if="flatRows.length === 0" class="empty">
          暂无分类，点击上方「+ 新增分类」创建
        </div>
      </div>
    </div>

    <template #footer>
      <d-button type="primary" @click="close">完成</d-button>
    </template>
  </d-modal>
</template>

<style scoped>
.category-manage {
  min-width: 380px;
  max-width: 560px;
  max-height: 60vh;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.category-manage__toolbar {
  display: flex;
  justify-content: flex-end;
}

.add-form {
  background: #f7f8fa;
  border: 1px solid #e5e6eb;
  border-radius: 6px;
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.add-form__row {
  display: flex;
  gap: 12px;
}

.add-form__row .form-field {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.form-label {
  font-size: 12px;
  color: #4e5969;
  font-weight: 500;
}

.add-form__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.category-list {
  flex: 1;
  overflow-y: auto;
  border: 1px solid #e5e6eb;
  border-radius: 6px;
  background: #fff;
}

.category-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  border-bottom: 1px solid #f2f3f5;
  min-height: 40px;
}

.category-row:last-child {
  border-bottom: none;
}

.category-row:hover {
  background: #f7f8fa;
}

.category-row__name {
  flex: 1;
  font-size: 13px;
  color: #1c1f23;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.category-row__edit-input {
  flex: 1;
}

.category-row__actions {
  display: flex;
  gap: 8px;
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

.text-btn--danger {
  color: #f53f3f;
}

.text-btn--danger:hover {
  background: #ffece8;
}

.empty {
  text-align: center;
  color: #86909c;
  font-size: 13px;
  padding: 32px 12px;
}
</style>
