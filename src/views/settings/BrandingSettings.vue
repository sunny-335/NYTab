<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Message } from 'vue-devui'
import { brandingApi } from '@/api/branding.api'
import type { Branding } from '@/api/branding.api'

/**
 * BrandingSettings — 个性化设置子页。
 *
 * - GET /api/branding 加载当前 nickname/title/logo(copyright 只读展示)
 * - Logo 上传 POST /api/branding/logo(multipart, PNG/JPG/SVG, ≤500KB)
 * - 保存按钮 PUT /api/branding,仅提交 nickname/title(logo 由上传接口更新)
 *
 * copyright 字段由后端硬编码(© 暖心向阳335),前端不显示输入框,
 * 也不允许通过 PUT 修改(后端会忽略客户端传入的 copyright)。
 */
const ACCEPTED_TYPES = ['image/png', 'image/jpeg', 'image/jpg', 'image/svg+xml']
const MAX_SIZE = 500 * 1024 // 500 KB
const NICKNAME_MAX = 32
const TITLE_MAX = 64

const loading = ref(true)
const saving = ref(false)
const uploading = ref(false)

const formNickname = ref('')
const formTitle = ref('')
const formLogo = ref('')
const fileInput = ref<HTMLInputElement | null>(null)

/** 当前生效的 branding(用于显示 copyright 与 logo 预览)。 */
const branding = ref<Branding | null>(null)

const nicknameError = computed(() => {
  const v = formNickname.value.trim()
  if (!v) return '昵称不能为空'
  if (v.length > NICKNAME_MAX) return `昵称长度需为 1-${NICKNAME_MAX} 字符`
  return ''
})

const titleError = computed(() => {
  const v = formTitle.value.trim()
  if (!v) return '网站 title 不能为空'
  if (v.length > TITLE_MAX) return `title 长度需为 1-${TITLE_MAX} 字符`
  return ''
})

/** logo 由上传接口单独更新,表单内 nickname/title 改动后即可保存。 */
const canSave = computed(
  () =>
    !loading.value &&
    !saving.value &&
    !uploading.value &&
    nicknameError.value === '' &&
    titleError.value === '' &&
    (formNickname.value.trim() !== (branding.value?.nickname || '') ||
      formTitle.value.trim() !== (branding.value?.title || '')),
)

function syncFromBranding(b: Branding): void {
  branding.value = b
  formNickname.value = b.nickname
  formTitle.value = b.title
  formLogo.value = b.logo
}

async function loadBranding(): Promise<void> {
  loading.value = true
  try {
    const data = await brandingApi.get()
    syncFromBranding(data)
  } catch {
    // 拦截器已 toast
  } finally {
    loading.value = false
  }
}

async function handleSave(): Promise<void> {
  if (!canSave.value) return
  saving.value = true
  try {
    const updated = await brandingApi.update({
      nickname: formNickname.value.trim(),
      title: formTitle.value.trim(),
    })
    syncFromBranding(updated)
    Message.success('品牌信息已保存')
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
  input.value = ''

  if (!ACCEPTED_TYPES.includes(file.type)) {
    Message.error('仅支持 PNG / JPG / SVG 格式')
    return
  }
  if (file.size > MAX_SIZE) {
    Message.error('Logo 大小不能超过 500KB')
    return
  }

  uploading.value = true
  try {
    const updated = await brandingApi.uploadLogo(file)
    syncFromBranding(updated)
    Message.success('Logo 已上传')
  } catch {
    // 拦截器已 toast
  } finally {
    uploading.value = false
  }
}

onMounted(() => {
  void loadBranding()
})
</script>

<template>
  <div class="branding-settings">
    <h1 class="page-title">个性化</h1>

    <div v-if="loading" class="loading-hint">加载中…</div>

    <template v-else>
      <!-- 基本信息 -->
      <section class="settings-card">
        <h2 class="card-title">基本信息</h2>

        <label class="form-field">
          <span class="form-label">昵称</span>
          <d-input
            v-model="formNickname"
            :maxlength="NICKNAME_MAX"
            placeholder="1-32 字符"
          />
          <small class="form-tip">
            显示在登录页 / 安装向导 / 顶部导航的品牌名称。
          </small>
          <small v-if="nicknameError" class="field-error">
            {{ nicknameError }}
          </small>
        </label>

        <label class="form-field">
          <span class="form-label">网站 Title</span>
          <d-input
            v-model="formTitle"
            :maxlength="TITLE_MAX"
            placeholder="1-64 字符,将作为浏览器标签页标题"
          />
          <small class="form-tip">
            浏览器标签页标题(HTML &lt;title&gt;)。
          </small>
          <small v-if="titleError" class="field-error">
            {{ titleError }}
          </small>
        </label>

        <div class="card-actions">
          <d-button
            type="primary"
            :loading="saving"
            :disabled="!canSave"
            @click="handleSave"
          >
            保存
          </d-button>
        </div>
      </section>

      <!-- Logo 上传 -->
      <section class="settings-card">
        <h2 class="card-title">Logo</h2>
        <p class="card-desc">
          支持 PNG / JPG / SVG,大小不超过 500KB。上传后将自动应用。
        </p>

        <div class="logo-row">
          <div class="logo-preview">
            <img
              v-if="formLogo"
              :src="formLogo"
              alt="logo"
              @error="(e: Event) => ((e.target as HTMLImageElement).style.opacity = '0.2')"
            />
            <span v-else class="logo-preview__placeholder">无</span>
          </div>
          <div class="logo-actions">
            <d-button
              type="primary"
              :loading="uploading"
              @click="pickFile"
            >
              选择并上传 Logo
            </d-button>
            <small v-if="formLogo" class="logo-current">
              当前:<code>{{ formLogo }}</code>
            </small>
          </div>
        </div>
        <input
          ref="fileInput"
          type="file"
          class="logo-input"
          accept="image/png,image/jpeg,image/svg+xml"
          @change="onFileChange"
        />
      </section>

      <!-- 版权信息(只读) -->
      <section class="settings-card">
        <h2 class="card-title">版权信息</h2>
        <p class="card-desc">
          版权字段由系统硬编码,不可修改。
        </p>
        <div class="readonly-field">
          <span class="form-label">Copyright</span>
          <div class="readonly-value">
            {{ branding?.copyright || '© 暖心向阳335' }}
          </div>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.branding-settings {
  max-width: 720px;
}

.page-title {
  margin: 0 0 24px;
  font-size: 22px;
  font-weight: 600;
  color: #1c1f23;
}

.loading-hint {
  padding: 32px;
  text-align: center;
  color: #86909c;
  font-size: 14px;
}

.settings-card {
  background: #fff;
  border: 1px solid #e5e6eb;
  border-radius: 8px;
  padding: 24px;
  margin-bottom: 20px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.card-title {
  margin: 0 0 4px;
  font-size: 16px;
  font-weight: 600;
  color: #1c1f23;
}

.card-desc {
  margin: 0;
  font-size: 13px;
  color: #86909c;
  line-height: 1.5;
}

.form-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  width: 100%;
  max-width: 420px;
}

.form-label {
  font-size: 13px;
  color: #4e5969;
  font-weight: 500;
}

.form-tip {
  color: #86909c;
  font-size: 12px;
}

.field-error {
  color: #f53f3f;
  font-size: 12px;
}

.card-actions {
  margin-top: 4px;
}

/* Logo 上传 */
.logo-row {
  display: flex;
  align-items: center;
  gap: 20px;
  flex-wrap: wrap;
}

.logo-preview {
  width: 96px;
  height: 96px;
  border: 1px solid #e5e6eb;
  border-radius: 8px;
  background: #f7f8fa;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  flex-shrink: 0;
}

.logo-preview img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  padding: 6px;
}

.logo-preview__placeholder {
  color: #c9cdd4;
  font-size: 13px;
}

.logo-actions {
  display: flex;
  flex-direction: column;
  gap: 8px;
  align-items: flex-start;
}

.logo-current {
  font-size: 12px;
  color: #4e5969;
  word-break: break-all;
}

.logo-current code {
  background: #f2f3f5;
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 12px;
}

.logo-input {
  display: none;
}

/* 只读字段 */
.readonly-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  max-width: 420px;
}

.readonly-value {
  padding: 8px 12px;
  background: #f7f8fa;
  border: 1px solid #e5e6eb;
  border-radius: 6px;
  font-size: 14px;
  color: #4e5969;
  font-weight: 500;
}
</style>
