<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { usePluginState } from '@/composables/usePluginState'

/**
 * ColorPicker — HEX / RGB / HSL 三种格式互转的颜色拾取器。
 *
 * - 原生 <input type="color"> 选色
 * - 三个文本输入框显示 HEX/RGB/HSL，任一改变实时同步其他
 * - 10 个预设调色板色块点击切换
 * - 复制按钮复制当前 HEX
 *
 * 内部以 hex 作为单一数据源（state.hex），rgb/hsl 通过 computed 派生，
 * 输入框失焦时校验并反向写回 hex。
 */
interface ColorState {
  hex: string
  [key: string]: unknown
}

const { state } = usePluginState<ColorState>('color-picker', {
  defaultState: () => ({ hex: '#3b82f6' }),
})

const hex = ref<string>(state.value?.hex ?? '#3b82f6')
const rgbInput = ref<string>('')
const hslInput = ref<string>('')
const errorMsg = ref<string>('')
const copyOk = ref<boolean>(false)

const PALETTE: string[] = [
  '#f53f3f', '#ff7d00', '#ffb400', '#00b42a',
  '#1668dc', '#722ed1', '#eb2f96', '#14c9c9',
  '#1c1f23', '#86909c',
]

watch(hex, (val) => {
  if (state.value) state.value.hex = val
  rgbInput.value = hexToRgbString(val)
  hslInput.value = hexToHslString(val)
}, { immediate: true })

const previewStyle = computed(() => ({ background: hex.value }))

function normalizeHex(input: string): string | null {
  let s = input.trim()
  if (!s.startsWith('#')) s = '#' + s
  if (/^#[0-9a-fA-F]{3}$/.test(s)) {
    // 展开 #abc -> #aabbcc
    s = '#' + s[1] + s[1] + s[2] + s[2] + s[3] + s[3]
  }
  if (/^#[0-9a-fA-F]{6}$/.test(s)) return s.toLowerCase()
  return null
}

function hexToRgbString(h: string): string {
  const n = normalizeHex(h)
  if (!n) return ''
  const r = parseInt(n.slice(1, 3), 16)
  const g = parseInt(n.slice(3, 5), 16)
  const b = parseInt(n.slice(5, 7), 16)
  return `rgb(${r}, ${g}, ${b})`
}

function hexToHslString(h: string): string {
  const n = normalizeHex(h)
  if (!n) return ''
  const r = parseInt(n.slice(1, 3), 16) / 255
  const g = parseInt(n.slice(3, 5), 16) / 255
  const b = parseInt(n.slice(5, 7), 16) / 255
  const max = Math.max(r, g, b)
  const min = Math.min(r, g, b)
  const l = (max + min) / 2
  let s = 0
  let hue = 0
  if (max !== min) {
    const d = max - min
    s = l > 0.5 ? d / (2 - max - min) : d / (max + min)
    switch (max) {
      case r: hue = (g - b) / d + (g < b ? 6 : 0); break
      case g: hue = (b - r) / d + 2; break
      case b: hue = (r - g) / d + 4; break
    }
    hue *= 60
  }
  return `hsl(${Math.round(hue)}, ${Math.round(s * 100)}%, ${Math.round(l * 100)}%)`
}

function rgbToHex(input: string): string | null {
  const m = input.match(/(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/)
  if (!m) return null
  const [r, g, b] = [m[1], m[2], m[3]].map((v) => parseInt(v, 10))
  if ([r, g, b].some((v) => v < 0 || v > 255)) return null
  return (
    '#' +
    [r, g, b].map((v) => v.toString(16).padStart(2, '0')).join('')
  ).toLowerCase()
}

function hslToHex(input: string): string | null {
  const m = input.match(/(\d+)\s*,\s*(\d+)%?\s*,\s*(\d+)%?/)
  if (!m) return null
  const h = (parseInt(m[1], 10) % 360) / 360
  const s = parseInt(m[2], 10) / 100
  const l = parseInt(m[3], 10) / 100
  if (s < 0 || s > 1 || l < 0 || l > 1) return null
  let r: number, g: number, b: number
  if (s === 0) {
    r = g = b = l
  } else {
    const hue2rgb = (p: number, q: number, t: number) => {
      if (t < 0) t += 1
      if (t > 1) t -= 1
      if (t < 1 / 6) return p + (q - p) * 6 * t
      if (t < 1 / 2) return q
      if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6
      return p
    }
    const q = l < 0.5 ? l * (1 + s) : l + s - l * s
    const p = 2 * l - q
    r = hue2rgb(p, q, h + 1 / 3)
    g = hue2rgb(p, q, h)
    b = hue2rgb(p, q, h - 1 / 3)
  }
  return (
    '#' +
    [r, g, b]
      .map((v) => Math.round(v * 255).toString(16).padStart(2, '0'))
      .join('')
  ).toLowerCase()
}

function onHexInput(e: Event): void {
  const val = (e.target as HTMLInputElement).value
  const n = normalizeHex(val)
  if (n) {
    hex.value = n
    errorMsg.value = ''
  } else {
    errorMsg.value = 'HEX 格式无效（应为 #rgb 或 #rrggbb）'
  }
}

function onRgbInput(e: Event): void {
  const val = (e.target as HTMLInputElement).value
  const n = rgbToHex(val)
  if (n) {
    hex.value = n
    errorMsg.value = ''
  } else {
    errorMsg.value = 'RGB 格式无效（应为 r, g, b，0-255）'
  }
}

function onHslInput(e: Event): void {
  const val = (e.target as HTMLInputElement).value
  const n = hslToHex(val)
  if (n) {
    hex.value = n
    errorMsg.value = ''
  } else {
    errorMsg.value = 'HSL 格式无效（应为 h, s%, l%）'
  }
}

function onPickerChange(e: Event): void {
  const val = (e.target as HTMLInputElement).value
  hex.value = val
  errorMsg.value = ''
}

function selectPalette(c: string): void {
  hex.value = c
  errorMsg.value = ''
}

async function copyHex(): Promise<void> {
  try {
    await navigator.clipboard.writeText(hex.value)
    copyOk.value = true
    setTimeout(() => {
      copyOk.value = false
    }, 1500)
  } catch {
    errorMsg.value = '复制失败：浏览器不支持或权限被拒'
  }
}
</script>

<template>
  <div class="color-picker">
    <div class="color-picker__preview" :style="previewStyle">
      <span class="color-picker__hex-label">{{ hex }}</span>
    </div>

    <div class="color-picker__native">
      <label class="color-picker__field">
        <span>原生选色</span>
        <input
          type="color"
          :value="hex"
          class="color-picker__native-input"
          @input="onPickerChange"
        />
      </label>
      <d-button size="sm" type="primary" @click="copyHex">
        {{ copyOk ? '已复制 ✓' : '复制 HEX' }}
      </d-button>
    </div>

    <div class="color-picker__fields">
      <label class="color-picker__field">
        <span>HEX</span>
        <input
          :value="hex"
          class="color-picker__input"
          spellcheck="false"
          @change="onHexInput"
        />
      </label>
      <label class="color-picker__field">
        <span>RGB</span>
        <input
          v-model="rgbInput"
          class="color-picker__input"
          spellcheck="false"
          @change="onRgbInput"
        />
      </label>
      <label class="color-picker__field">
        <span>HSL</span>
        <input
          v-model="hslInput"
          class="color-picker__input"
          spellcheck="false"
          @change="onHslInput"
        />
      </label>
    </div>

    <div v-if="errorMsg" class="color-picker__error">{{ errorMsg }}</div>

    <div class="color-picker__palette">
      <button
        v-for="c in PALETTE"
        :key="c"
        type="button"
        class="color-picker__swatch"
        :class="{ 'is-active': hex.toLowerCase() === c.toLowerCase() }"
        :style="{ background: c }"
        :title="c"
        @click="selectPalette(c)"
      />
    </div>
  </div>
</template>

<style scoped>
.color-picker {
  display: flex;
  flex-direction: column;
  gap: 10px;
  height: 100%;
  min-height: 0;
}

.color-picker__preview {
  height: 70px;
  border-radius: 6px;
  border: 1px solid #e5e6eb;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
}

.color-picker__hex-label {
  font-family: 'Menlo', 'Consolas', monospace;
  font-size: 14px;
  font-weight: 600;
  color: #fff;
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
  letter-spacing: 0.5px;
}

.color-picker__native {
  display: flex;
  align-items: flex-end;
  gap: 10px;
}

.color-picker__fields {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.color-picker__field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  flex: 1;
  min-width: 0;
}

.color-picker__field > span {
  font-size: 12px;
  color: #86909c;
  font-weight: 500;
}

.color-picker__native-input {
  width: 48px;
  height: 32px;
  padding: 0;
  border: 1px solid #e5e6eb;
  border-radius: 6px;
  cursor: pointer;
  background: #fff;
}

.color-picker__native-input::-webkit-color-swatch-wrapper {
  padding: 2px;
}

.color-picker__native-input::-webkit-color-swatch {
  border: none;
  border-radius: 4px;
}

.color-picker__input {
  width: 100%;
  height: 30px;
  border: 1px solid #e5e6eb;
  border-radius: 6px;
  padding: 0 10px;
  font-family: 'Menlo', 'Consolas', monospace;
  font-size: 12px;
  color: #1c1f23;
  background: #fff;
  outline: none;
  box-sizing: border-box;
}

.color-picker__input:focus {
  border-color: #1668dc;
  box-shadow: 0 0 0 2px rgba(22, 104, 220, 0.12);
}

.color-picker__error {
  font-size: 12px;
  color: #f53f3f;
  background: #ffece8;
  padding: 4px 8px;
  border-radius: 4px;
}

.color-picker__palette {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 6px;
}

.color-picker__swatch {
  aspect-ratio: 1 / 1;
  border: 2px solid transparent;
  border-radius: 6px;
  cursor: pointer;
  padding: 0;
  outline: none;
  transition: transform 0.1s, border-color 0.15s;
}

.color-picker__swatch:hover {
  transform: scale(1.06);
}

.color-picker__swatch.is-active {
  border-color: #1c1f23;
  box-shadow: 0 0 0 2px #fff inset;
}
</style>
