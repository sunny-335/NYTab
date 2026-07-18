<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Message } from 'vue-devui'
import { useBackgroundStore } from '@/stores/background.store'
import { useBreakpoint } from '@/composables/useBreakpoint'
import type { BackgroundType } from '@/api/background.api'

/**
 * BackgroundSettings — 背景设置页。
 *
 * 三种模式(Bing 壁纸 / API 链接 / 上传图片)通过 d-radio-group 切换:
 *   - Bing:  无需配置,直接保存即可。
 *   - API:   填写图片 URL,保存后生效。
 *   - 上传:  选择本地图片(≤5MB,JPG/PNG/WebP),上传后自动生效。
 *
 * 页面内置实时预览框,根据当前表单状态即时展示背景效果。
 * 实际全站背景(<Background />)在 Save / 上传成功后通过 store 响应式更新。
 */
const store = useBackgroundStore()
const { isMobile } = useBreakpoint()

const BING_BASE = 'https://bing.img.run/api.html'
const ACCEPTED_TYPES = ['image/png', 'image/jpeg', 'image/webp']
const MAX_SIZE = 5 * 1024 * 1024 // 5 MB

const formType = ref<BackgroundType>('bing')
const formUrl = ref('')
const saving = ref(false)
const uploading = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

const isApi = computed(() => formType.value === 'api')
const isBing = computed(() => formType.value === 'bing')

/** 预览背景图 URL(基于当前表单状态,实时更新)。 */
const previewUrl = computed<string>(() => {
  if (isBing.value) {
    const type = isMobile.value ? 'mobile' : 'pc'
    return `${BING_BASE}?type=${type}`
  }
  return formUrl.value
})

const hasPreview = computed(() => previewUrl.value !== '')

const previewStyle = computed(() => {
  if (!hasPreview.value) return {}
  return { backgroundImage: `url("${previewUrl.value}")` }
})

/** API 模式下 URL 必填;image 模式下需有 url(通常由上传产生)。 */
const urlValid = computed(() => {
  if (isBing.value) return true
  return formUrl.value.trim() !== ''
})

const canSave = computed(
  () => urlValid.value && !saving.value && !uploading.value,
)

function syncFromStore(): void {
  const cfg = store.background
  if (!cfg) return
  formType.value = cfg.type
  formUrl.value = cfg.url
}

onMounted(async () => {
  await store.fetchBackground()
  syncFromStore()
})

async function handleSave(): Promise<void> {
  if (!canSave.value) return
  saving.value = true
  try {
    await store.updateBackground({
      type: formType.value,
      url: isBing.value ? '' : formUrl.value.trim(),
    })
    syncFromStore()
    Message.success('背景设置已保存')
  } catch {
    // 拦截器已 toast
  } finally {
    saving.value = false
  }
}

function pickFile(): void {
  fileInput.value?.click()
}

async function onFileChange(e: Event): Promise<void> {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  // 重置 input.value 以便重复选择同一文件
  input.value = ''

  if (!ACCEPTED_TYPES.includes(file.type)) {
    Message.error('仅支持 JPG / PNG / WebP 格式')
    return
  }
  if (file.size > MAX_SIZE) {
    Message.error('图片大小不能超过 5MB')
    return
  }

  uploading.value = true
  try {
    const url = await store.uploadBackground(file)
    formType.value = 'image'
    formUrl.value = url
    Message.success('背景图片已上传')
  } catch {
    // 拦截器已 toast
  } finally {
    uploading.value = false
  }
}
</script>

<template>
  <div class="bg-settings">
    <h1 class="page-title">背景设置</h1>

    <div class="bg-card">
      <h2 class="card-title">背景类型</h2>
      <d-radio-group v-model="formType" direction="row" class="bg-type">
        <d-radio value="bing">Bing 每日壁纸</d-radio>
        <d-radio value="api">API 链接</d-radio>
        <d-radio value="image">上传图片</d-radio>
      </d-radio-group>

      <!-- Bing 模式 -->
      <div v-if="isBing" class="bg-section">
        <p class="bg-hint">
          将根据设备尺寸自动切换 PC / 移动端 Bing 每日壁纸,无需额外配置。
        </p>
      </div>

      <!-- API 模式 -->
      <div v-else-if="isApi" class="bg-section">
        <label class="bg-field">
          <span>图片 API 链接</span>
          <d-input
            v-model="formUrl"
            placeholder="https://example.com/wallpaper.jpg"
          />
        </label>
        <small class="bg-tip">
          填写一个返回图片的 URL,将作为背景图直接加载。
        </small>
      </div>

      <!-- 上传模式 -->
      <div v-else class="bg-section">
        <p class="bg-hint">
          支持 JPG / PNG / WebP,单张不超过 5MB。上传后自动应用为背景。
        </p>
        <div class="bg-upload">
          <d-button
            type="primary"
            :loading="uploading"
            @click="pickFile"
          >
            选择并上传图片
          </d-button>
          <input
            ref="fileInput"
            type="file"
            class="bg-upload__input"
            accept="image/png,image/jpeg,image/webp"
            @change="onFileChange"
          />
        </div>
        <div v-if="formUrl" class="bg-current">
          当前图片:<code>{{ formUrl }}</code>
        </div>
      </div>

      <div class="bg-actions">
        <d-button
          type="primary"
          :loading="saving"
          :disabled="!canSave"
          @click="handleSave"
        >
          保存
        </d-button>
      </div>
    </div>

    <div class="bg-card">
      <h2 class="card-title">实时预览</h2>
      <div class="bg-preview" :style="previewStyle">
        <span v-if="!hasPreview" class="bg-preview__empty">暂无预览</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.bg-settings {
  max-width: 720px;
}

.page-title {
  margin: 0 0 24px;
  font-size: 22px;
  font-weight: 600;
}

.bg-card {
  background: #fff;
  border: 1px solid #e5e6eb;
  border-radius: 8px;
  padding: 24px;
  margin-bottom: 20px;
}

.card-title {
  margin: 0 0 16px;
  font-size: 16px;
  font-weight: 600;
}

.bg-type {
  margin-bottom: 8px;
}

.bg-section {
  margin-top: 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  align-items: flex-start;
}

.bg-hint {
  margin: 0;
  font-size: 13px;
  color: #86909c;
  line-height: 1.6;
}

.bg-tip {
  color: #86909c;
  font-size: 12px;
}

.bg-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  width: 100%;
  max-width: 420px;
}

.bg-field > span {
  font-size: 13px;
  color: #4e5969;
  font-weight: 500;
}

.bg-upload {
  display: flex;
  align-items: center;
  gap: 12px;
}

.bg-upload__input {
  display: none;
}

.bg-current {
  font-size: 12px;
  color: #4e5969;
  word-break: break-all;
}

.bg-current code {
  background: #f2f3f5;
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 12px;
}

.bg-actions {
  margin-top: 20px;
}

.bg-preview {
  width: 100%;
  aspect-ratio: 16 / 9;
  background-position: center;
  background-size: cover;
  background-repeat: no-repeat;
  background-color: #f2f3f5;
  border: 1px solid #e5e6eb;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.bg-preview__empty {
  color: #c9cdd4;
  font-size: 13px;
}
</style>
