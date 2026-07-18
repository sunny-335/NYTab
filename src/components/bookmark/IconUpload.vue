<script setup lang="ts">
import { ref } from 'vue'
import { Message } from 'vue-devui'
import { bookmarkApi } from '@/api/bookmark.api'

/**
 * IconUpload — 书签图标上传/自动获取组件。
 *
 * 显示当前图标（如有），提供「上传图标」（隐藏 file input）与「自动获取」
 * 两个按钮。文件选择后调用 `bookmarkApi.uploadIcon`，自动获取调用
 * `bookmarkApi.fetchIcon`（注意：当前后端未注册该路由，调用会得到 404）。
 *
 * 限制：
 *   - 允许 MIME：image/png、image/jpeg、image/svg+xml、image/x-icon
 *   - 文件大小 ≤ 1MB（前端校验；后端另有 2MB 上限）
 */
const props = defineProps<{
  bookmarkId: number
  currentIcon?: string | null
}>()

const emit = defineEmits<{
  (e: 'uploaded', iconUrl: string): void
}>()

const ACCEPTED_TYPES = [
  'image/png',
  'image/jpeg',
  'image/svg+xml',
  'image/x-icon',
  'image/vnd.microsoft.icon',
]
const MAX_SIZE = 1 * 1024 * 1024 // 1 MB

const fileInput = ref<HTMLInputElement | null>(null)
const uploading = ref(false)
const fetching = ref(false)
const previewUrl = ref<string | null>(props.currentIcon ?? null)

function pickFile() {
  fileInput.value?.click()
}

async function onFileChange(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  // 重置 input.value 以便重复选择同一文件
  input.value = ''

  if (!ACCEPTED_TYPES.includes(file.type)) {
    Message.error('仅支持 PNG / JPEG / SVG / ICO 格式')
    return
  }
  if (file.size > MAX_SIZE) {
    Message.error('图标大小不能超过 1MB')
    return
  }

  uploading.value = true
  try {
    const res = await bookmarkApi.uploadIcon(props.bookmarkId, file)
    previewUrl.value = res.icon_url
    emit('uploaded', res.icon_url)
    Message.success('图标已更新')
  } catch {
    // 拦截器已 toast
  } finally {
    uploading.value = false
  }
}

async function onFetchIcon() {
  fetching.value = true
  try {
    const res = await bookmarkApi.fetchIcon(props.bookmarkId)
    previewUrl.value = res.icon_url
    emit('uploaded', res.icon_url)
    Message.success('图标已获取')
  } catch {
    // 拦截器已 toast（当前后端会返回 404）
  } finally {
    fetching.value = false
  }
}
</script>

<template>
  <div class="icon-upload">
    <div class="icon-upload__preview">
      <img
        v-if="previewUrl"
        :src="previewUrl"
        alt="icon"
        referrerpolicy="no-referrer"
        @error="(e: Event) => (e.target as HTMLImageElement).style.opacity = '0.2'"
      />
      <span v-else class="icon-upload__placeholder">无</span>
    </div>
    <div class="icon-upload__actions">
      <d-button size="small" :loading="uploading" @click="pickFile">
        上传图标
      </d-button>
      <d-button
        size="small"
        type="common"
        :loading="fetching"
        @click="onFetchIcon"
      >
        自动获取
      </d-button>
    </div>
    <input
      ref="fileInput"
      type="file"
      class="icon-upload__input"
      accept="image/png,image/jpeg,image/svg+xml,image/x-icon"
      @change="onFileChange"
    />
  </div>
</template>

<style scoped>
.icon-upload {
  display: flex;
  align-items: center;
  gap: 12px;
}

.icon-upload__preview {
  width: 48px;
  height: 48px;
  border: 1px solid #e5e6eb;
  border-radius: 6px;
  background: #f7f8fa;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  flex-shrink: 0;
}

.icon-upload__preview img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.icon-upload__placeholder {
  color: #c9cdd4;
  font-size: 12px;
}

.icon-upload__actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.icon-upload__input {
  display: none;
}
</style>
