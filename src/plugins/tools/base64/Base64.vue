<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { usePluginState } from '@/composables/usePluginState'

/**
 * Base64 — UTF-8 安全的 Base64 编解码工具。
 *
 * 使用 TextEncoder / TextDecoder 处理 UTF-8，正确支持中文：
 * - 编码：将字符串按 UTF-8 转字节数组，再逐字节拼成二进制串调用 btoa
 * - 解码：atob 解出二进制串，转回字节数组后用 TextDecoder 解 UTF-8
 *
 * 通过 usePluginState 持久化模式与输入内容。
 */
type Mode = 'encode' | 'decode'

interface Base64State {
  mode: Mode
  input: string
  [key: string]: unknown
}

const { state } = usePluginState<Base64State>('base64', {
  defaultState: () => ({ mode: 'encode', input: '' }),
})

const mode = ref<Mode>(state.value?.mode ?? 'encode')
const input = ref<string>(state.value?.input ?? '')
const output = ref<string>('')
const errorMsg = ref<string>('')

const canProcess = computed(() => input.value.trim().length > 0)

watch(mode, (val) => {
  if (state.value) state.value.mode = val
})

watch(input, (val) => {
  if (state.value) state.value.input = val
})

function encodeBase64(str: string): string {
  const bytes = new TextEncoder().encode(str)
  let binary = ''
  for (const b of bytes) {
    binary += String.fromCharCode(b)
  }
  return btoa(binary)
}

function decodeBase64(b64: string): string {
  const binary = atob(b64.trim())
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i)
  }
  return new TextDecoder('utf-8').decode(bytes)
}

function doProcess(): void {
  errorMsg.value = ''
  if (!input.value) {
    output.value = ''
    return
  }
  try {
    if (mode.value === 'encode') {
      output.value = encodeBase64(input.value)
    } else {
      output.value = decodeBase64(input.value)
    }
  } catch (e) {
    errorMsg.value = mode.value === 'decode' ? '解码失败：输入不是有效的 Base64' : (e as Error).message
    output.value = ''
  }
}

async function copyOutput(): Promise<void> {
  if (!output.value) return
  try {
    await navigator.clipboard.writeText(output.value)
    errorMsg.value = ''
  } catch {
    errorMsg.value = '复制失败：浏览器不支持或权限被拒'
  }
}

function clearAll(): void {
  input.value = ''
  output.value = ''
  errorMsg.value = ''
}
</script>

<template>
  <div class="base64">
    <div class="base64__bar">
      <d-radio-group
        v-model="mode"
        direction="row"
        class="base64__radio"
      >
        <d-radio value="encode">编码</d-radio>
        <d-radio value="decode">解码</d-radio>
      </d-radio-group>
      <div class="base64__actions">
        <d-button size="sm" type="primary" :disabled="!canProcess" @click="doProcess">
          {{ mode === 'encode' ? '编码' : '解码' }}
        </d-button>
        <d-button size="sm" :disabled="!output" @click="copyOutput">
          复制
        </d-button>
        <d-button size="sm" type="common" @click="clearAll">清空</d-button>
      </div>
    </div>

    <div v-if="errorMsg" class="base64__error">{{ errorMsg }}</div>

    <div class="base64__panes">
      <div class="base64__pane">
        <label class="base64__label">
          {{ mode === 'encode' ? '原文输入' : 'Base64 输入' }}
        </label>
        <textarea
          v-model="input"
          class="base64__textarea"
          :placeholder="mode === 'encode' ? '输入要编码的文本…' : '输入要解码的 Base64…'"
          spellcheck="false"
        />
      </div>
      <div class="base64__pane">
        <label class="base64__label">
          {{ mode === 'encode' ? 'Base64 输出' : '原文输出' }}
        </label>
        <textarea
          v-model="output"
          class="base64__textarea"
          readonly
          spellcheck="false"
          placeholder="结果…"
        />
      </div>
    </div>
  </div>
</template>

<style scoped>
.base64 {
  display: flex;
  flex-direction: column;
  gap: 8px;
  height: 100%;
  min-height: 0;
}

.base64__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  flex-wrap: wrap;
}

.base64__radio {
  display: flex;
  align-items: center;
  gap: 16px;
}

.base64__actions {
  display: flex;
  gap: 6px;
}

.base64__error {
  font-size: 12px;
  color: #f53f3f;
  background: #ffece8;
  padding: 4px 8px;
  border-radius: 4px;
}

.base64__panes {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  flex: 1;
  min-height: 0;
}

.base64__pane {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  min-height: 0;
}

.base64__label {
  font-size: 12px;
  color: #86909c;
  font-weight: 500;
}

.base64__textarea {
  flex: 1;
  min-height: 80px;
  width: 100%;
  resize: none;
  border: 1px solid #e5e6eb;
  border-radius: 6px;
  padding: 8px 10px;
  font-family: 'Menlo', 'Consolas', monospace;
  font-size: 12px;
  line-height: 1.5;
  color: #1c1f23;
  background: #fff;
  outline: none;
  box-sizing: border-box;
}

.base64__textarea:focus {
  border-color: #1668dc;
  box-shadow: 0 0 0 2px rgba(22, 104, 220, 0.12);
}
</style>
