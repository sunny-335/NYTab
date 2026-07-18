<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { Message } from 'vue-devui'
import { useBookmarkStore } from '@/stores/bookmark.store'
import type { Bookmark, BookmarkWritePayload } from '@/api/bookmark.api'
import IconUpload from './IconUpload.vue'

/**
 * BookmarkEditModal — 新增/编辑书签弹窗。
 *
 * 通过 `bookmark` prop 区分模式：
 *   - 传入 bookmark：编辑模式，预填表单 + 显示 IconUpload
 *   - 不传 / undefined：新增模式，空表单
 *
 * 标签输入框接受逗号分隔字符串，提交时拆分为数组并通过
 * `extra.tags` 写入后端 JSONB。
 *
 * 由于 vue-devui 的 d-select modelValue 不接受 null，
 * 这里用 `0` 作为「未分类」哨兵，提交/回填时与 null 互转。
 */
const props = defineProps<{
  visible: boolean
  bookmark?: Bookmark | null
}>()

const emit = defineEmits<{
  (e: 'update:visible', val: boolean): void
  (e: 'saved'): void
}>()

const bookmarkStore = useBookmarkStore()

const isEdit = computed(() => !!props.bookmark)

const title = ref('')
const url = ref('')
const description = ref('')
const categoryId = ref<number>(0) // 0 = 未分类（提交时转 null）
const tagsInput = ref('')
const saving = ref(false)

/** d-select 选项：[未分类, ...带缩进的分类]。 */
const categoryOptions = computed(() => {
  const out: Array<{ value: number; name: string }> = [
    { value: 0, name: '未分类' },
  ]
  const walk = (nodes: typeof bookmarkStore.categoryTree, depth: number) => {
    for (const n of nodes) {
      const prefix = depth > 0 ? '　'.repeat(depth) + '└ ' : ''
      out.push({ value: n.id, name: prefix + n.name })
      if (n.children?.length) walk(n.children, depth + 1)
    }
  }
  walk(bookmarkStore.categoryTree, 0)
  return out
})

const urlError = computed(() => {
  const u = url.value.trim()
  if (!u) return ''
  if (!/^https?:\/\/.+/i.test(u)) return 'URL 需以 http:// 或 https:// 开头'
  return ''
})

const canSubmit = computed(
  () =>
    title.value.trim() !== '' &&
    url.value.trim() !== '' &&
    !urlError.value &&
    !saving.value,
)

/** 弹窗打开时同步表单状态。 */
watch(
  () => props.visible,
  (open) => {
    if (!open) return
    if (props.bookmark) {
      title.value = props.bookmark.title
      url.value = props.bookmark.url
      description.value = props.bookmark.description ?? ''
      categoryId.value = props.bookmark.category_id ?? 0
      tagsInput.value = (props.bookmark.extra?.tags ?? []).join(', ')
    } else {
      title.value = ''
      url.value = ''
      description.value = ''
      categoryId.value = 0
      tagsInput.value = ''
    }
  },
)

function parseTags(input: string): string[] {
  return input
    .split(/[,，]/)
    .map((t) => t.trim())
    .filter((t) => t.length > 0)
}

function close() {
  emit('update:visible', false)
}

async function handleSubmit() {
  if (!canSubmit.value) return
  saving.value = true
  try {
    const payload: BookmarkWritePayload = {
      title: title.value.trim(),
      url: url.value.trim(),
      description: description.value.trim() || null,
      category_id: categoryId.value === 0 ? null : categoryId.value,
      extra: { tags: parseTags(tagsInput.value) },
    }
    if (isEdit.value && props.bookmark) {
      await bookmarkStore.updateBookmark(props.bookmark.id, payload)
      Message.success('书签已更新')
    } else {
      await bookmarkStore.createBookmark(payload)
      Message.success('书签已添加')
    }
    emit('saved')
    close()
  } catch {
    // 拦截器已 toast
  } finally {
    saving.value = false
  }
}

function onIconUploaded(iconUrl: string) {
  if (props.bookmark) {
    // 同步本地 bookmark 副本，避免下次打开时仍显示旧图标
    if (props.bookmark.icon_url !== iconUrl) {
      props.bookmark.icon_url = iconUrl
    }
  }
}
</script>

<template>
  <d-modal
    :model-value="visible"
    :title="isEdit ? '编辑书签' : '新增书签'"
    show-close
    show-overlay
    append-to-body
    @update:model-value="emit('update:visible', $event)"
  >
    <div class="bookmark-form">
      <label class="form-field">
        <span class="form-label">标题 <span class="required">*</span></span>
        <d-input v-model="title" placeholder="书签标题" />
      </label>

      <label class="form-field">
        <span class="form-label">URL <span class="required">*</span></span>
        <d-input v-model="url" placeholder="https://example.com" />
        <small v-if="urlError" class="field-error">{{ urlError }}</small>
      </label>

      <label class="form-field">
        <span class="form-label">描述</span>
        <d-textarea
          v-model="description"
          placeholder="可选，书签的简短描述"
          :rows="3"
        />
      </label>

      <label class="form-field">
        <span class="form-label">分类</span>
        <d-select
          v-model="categoryId"
          :options="categoryOptions"
          placeholder="选择分类"
        />
      </label>

      <label class="form-field">
        <span class="form-label">标签</span>
        <d-input
          v-model="tagsInput"
          placeholder="多个标签用逗号分隔，如：vue,ui,工具"
        />
      </label>

      <div v-if="isEdit && bookmark" class="form-field">
        <span class="form-label">图标</span>
        <IconUpload
          :bookmark-id="bookmark.id"
          :current-icon="bookmark.icon_url"
          @uploaded="onIconUploaded"
        />
      </div>
    </div>

    <template #footer>
      <d-button type="common" @click="close">取消</d-button>
      <d-button
        type="primary"
        :loading="saving"
        :disabled="!canSubmit"
        @click="handleSubmit"
      >
        {{ isEdit ? '保存' : '添加' }}
      </d-button>
    </template>
  </d-modal>
</template>

<style scoped>
.bookmark-form {
  min-width: 380px;
  max-width: 560px;
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 4px 0;
}

.form-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.form-label {
  font-size: 13px;
  color: #4e5969;
  font-weight: 500;
}

.required {
  color: #f53f3f;
}

.field-error {
  color: #f53f3f;
  font-size: 12px;
}
</style>
