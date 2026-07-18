<script setup lang="ts">
import { computed, ref } from 'vue'
import { usePluginState } from '@/composables/usePluginState'

type PasswordGenState = {
  length: number
  upper: boolean
  lower: boolean
  digit: boolean
  symbol: boolean
  excludeAmbiguous: boolean
  lastPassword: string
}

const CHARSETS = {
  upper: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
  lower: 'abcdefghijklmnopqrstuvwxyz',
  digit: '0123456789',
  symbol: '!@#$%^&*()_+-=[]{}|;:,.<>?',
}
const AMBIGUOUS = '0O1lI'

const { state } = usePluginState<PasswordGenState>('password-gen', {
  defaultState: () => ({
    length: 16,
    upper: true,
    lower: true,
    digit: true,
    symbol: true,
    excludeAmbiguous: false,
    lastPassword: '',
  }),
})

const copied = ref(false)
let copyTimer: ReturnType<typeof setTimeout> | null = null

function buildCharset(s: PasswordGenState): string {
  let chars = ''
  if (s.upper) chars += CHARSETS.upper
  if (s.lower) chars += CHARSETS.lower
  if (s.digit) chars += CHARSETS.digit
  if (s.symbol) chars += CHARSETS.symbol
  if (s.excludeAmbiguous) {
    chars = chars
      .split('')
      .filter((c) => !AMBIGUOUS.includes(c))
      .join('')
  }
  return chars
}

function countCategories(s: PasswordGenState): number {
  let n = 0
  if (s.upper) n++
  if (s.lower) n++
  if (s.digit) n++
  if (s.symbol) n++
  return n
}

function generatePassword(): string {
  const s = state.value
  if (!s) return ''
  const charset = buildCharset(s)
  if (!charset) return ''

  // crypto.getRandomValues 保证密码学安全随机
  const array = new Uint32Array(s.length)
  crypto.getRandomValues(array)

  let pwd = ''
  for (let i = 0; i < s.length; i++) {
    pwd += charset[array[i] % charset.length]
  }
  return pwd
}

function onGenerate() {
  const pwd = generatePassword()
  if (pwd && state.value) {
    state.value.lastPassword = pwd
  }
}

type Strength = 'weak' | 'medium' | 'strong'

const strength = computed<Strength>(() => {
  const s = state.value
  if (!s) return 'weak'
  const len = s.length
  const cats = countCategories(s)

  // 基础分（按长度）
  let score = 1
  if (len >= 16) score = 3
  else if (len >= 8) score = 2

  // 根据字符集种类数调整
  if (cats >= 4) score += 1
  else if (cats <= 1) score -= 1

  score = Math.max(1, Math.min(3, score))

  return score === 1 ? 'weak' : score === 2 ? 'medium' : 'strong'
})

const strengthLabel = computed(() =>
  strength.value === 'weak' ? '弱' : strength.value === 'medium' ? '中' : '强',
)

const strengthColor = computed(
  () =>
    strength.value === 'weak'
      ? '#f53f3f'
      : strength.value === 'medium'
        ? '#ff7d00'
        : '#00b42a',
)

const canGenerate = computed(() => {
  const s = state.value
  if (!s) return false
  return s.upper || s.lower || s.digit || s.symbol
})

async function copyPassword() {
  const s = state.value
  if (!s || !s.lastPassword) return
  try {
    await navigator.clipboard.writeText(s.lastPassword)
    copied.value = true
    if (copyTimer) clearTimeout(copyTimer)
    copyTimer = setTimeout(() => {
      copied.value = false
    }, 1500)
  } catch {
    // 降级：使用 execCommand
    const textarea = document.createElement('textarea')
    textarea.value = s.lastPassword
    textarea.style.position = 'fixed'
    textarea.style.opacity = '0'
    document.body.appendChild(textarea)
    textarea.select()
    try {
      document.execCommand('copy')
      copied.value = true
      if (copyTimer) clearTimeout(copyTimer)
      copyTimer = setTimeout(() => {
        copied.value = false
      }, 1500)
    } finally {
      document.body.removeChild(textarea)
    }
  }
}
</script>

<template>
  <div class="password-gen">
    <div v-if="state" class="pg-form">
      <div class="length-row">
        <span class="field-label">长度</span>
        <d-slider v-model="state.length" :min="4" :max="64" :step="1" />
        <span class="length-value">{{ state.length }}</span>
      </div>

      <div class="checkbox-group">
        <d-checkbox v-model="state.upper">大写字母 (A-Z)</d-checkbox>
        <d-checkbox v-model="state.lower">小写字母 (a-z)</d-checkbox>
        <d-checkbox v-model="state.digit">数字 (0-9)</d-checkbox>
        <d-checkbox v-model="state.symbol">符号 (!@#…)</d-checkbox>
        <d-checkbox v-model="state.excludeAmbiguous">排除易混淆 (0/O/1/l/I)</d-checkbox>
      </div>

      <d-button
        type="primary"
        :disabled="!canGenerate"
        @click="onGenerate"
      >
        生成密码
      </d-button>

      <div v-if="state.lastPassword" class="result-box">
        <div class="password-display">{{ state.lastPassword }}</div>
        <div class="meta-row">
          <span class="strength">
            强度：
            <span class="strength-label" :style="{ color: strengthColor }">
              {{ strengthLabel }}
            </span>
          </span>
          <d-button size="mini" type="common" @click="copyPassword">
            {{ copied ? '已复制' : '复制' }}
          </d-button>
        </div>
      </div>
      <div v-else-if="!canGenerate" class="warn">请至少选择一种字符集</div>
    </div>
    <div v-else class="hint">加载中…</div>
  </div>
</template>

<style scoped>
.password-gen {
  display: flex;
  flex-direction: column;
  gap: 12px;
  font-size: 13px;
}

.pg-form {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.length-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.length-row .field-label {
  font-size: 12px;
  color: #4e5969;
  font-weight: 500;
  min-width: 32px;
}

.length-row d-slider {
  flex: 1;
}

.length-value {
  min-width: 28px;
  text-align: right;
  font-variant-numeric: tabular-nums;
  color: #1668dc;
  font-weight: 600;
}

.checkbox-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.result-box {
  padding: 10px 12px;
  background: #f7f8fa;
  border-radius: 6px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.password-display {
  font-family: 'Consolas', 'Monaco', monospace;
  font-size: 14px;
  word-break: break-all;
  color: #1c1f23;
  letter-spacing: 0.5px;
}

.meta-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.strength {
  font-size: 13px;
  color: #4e5969;
}

.strength-label {
  font-weight: 600;
}

.field-label {
  font-size: 12px;
  color: #4e5969;
  font-weight: 500;
}

.hint {
  color: #86909c;
  font-size: 13px;
}

.warn {
  color: #ff7d00;
  font-size: 13px;
}
</style>
