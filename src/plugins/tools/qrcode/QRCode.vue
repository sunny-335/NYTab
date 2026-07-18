<script setup lang="ts">
import { computed, ref } from 'vue'
import { usePluginState } from '@/composables/usePluginState'

type QRCodeState = {
  text: string
  size: number
}

const SIZE_OPTIONS = [
  { name: '128 × 128', value: 128 },
  { name: '256 × 256', value: 256 },
  { name: '512 × 512', value: 512 },
]

const { state } = usePluginState<QRCodeState>('qrcode', {
  defaultState: () => ({ text: '', size: 256 }),
})

const downloading = ref(false)
const downloadError = ref('')

const qrUrl = computed(() => {
  const s = state.value
  if (!s || !s.text.trim()) return ''
  const data = encodeURIComponent(s.text)
  return `https://api.qrserver.com/v1/create-qr-code/?size=${s.size}x${s.size}&data=${data}`
})

async function downloadPng() {
  const url = qrUrl.value
  if (!url) return
  downloading.value = true
  downloadError.value = ''
  try {
    const res = await fetch(url)
    if (!res.ok) throw new Error(`HTTP ${res.status}`)
    const blob = await res.blob()
    const objectUrl = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = objectUrl
    a.download = `qrcode-${Date.now()}.png`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(objectUrl)
  } catch (e) {
    downloadError.value = (e as Error).message || '下载失败'
  } finally {
    downloading.value = false
  }
}
</script>

<template>
  <div class="qrcode">
    <div v-if="state" class="qr-form">
      <label class="field">
        <span class="field-label">文本 / URL</span>
        <d-textarea
          v-model="state.text"
          :autosize="{ minRows: 2, maxRows: 4 }"
          placeholder="输入文本或链接，实时生成二维码"
          show-count
        />
      </label>

      <label class="field">
        <span class="field-label">尺寸</span>
        <d-select v-model="state.size" :options="SIZE_OPTIONS" />
      </label>

      <div class="preview">
        <img
          v-if="qrUrl"
          :src="qrUrl"
          :width="state.size"
          :height="state.size"
          alt="二维码"
          class="qr-image"
        />
        <div v-else class="placeholder">
          请输入文本以生成二维码
        </div>
      </div>

      <div class="actions">
        <d-button
          type="primary"
          :disabled="!qrUrl"
          :loading="downloading"
          @click="downloadPng"
        >
          下载 PNG
        </d-button>
        <span v-if="downloadError" class="error">⚠ {{ downloadError }}</span>
      </div>
    </div>
    <div v-else class="hint">加载中…</div>
  </div>
</template>

<style scoped>
.qrcode {
  display: flex;
  flex-direction: column;
  gap: 12px;
  font-size: 13px;
}

.qr-form {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.field-label {
  font-size: 12px;
  color: #4e5969;
  font-weight: 500;
}

.preview {
  display: flex;
  justify-content: center;
  align-items: center;
  padding: 12px;
  background: #f7f8fa;
  border-radius: 6px;
  min-height: 140px;
}

.qr-image {
  max-width: 100%;
  max-height: 240px;
  width: auto;
  height: auto;
  display: block;
  border-radius: 4px;
}

.placeholder {
  color: #86909c;
  font-size: 13px;
  text-align: center;
}

.actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.hint {
  color: #86909c;
  font-size: 13px;
}

.error {
  color: #f53f3f;
  font-size: 13px;
}
</style>
