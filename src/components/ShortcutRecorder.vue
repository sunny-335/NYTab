<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import {
  formatKeys,
  normalizeComboString,
} from '@/stores/shortcuts.store'
import { setShortcutRecording } from '@/composables/useShortcuts'

/**
 * ShortcutRecorder — 按键录制器组件。
 *
 * 支持两种模式:
 * - sequence:记录连续按键(500ms 内),如 Z 然后 S → ['z','s']
 * - combo:记录同时按下的键(如 Ctrl+K)→ ['ctrl+k']
 *
 * 通过 v-model:modelValue (string[]) 双向绑定录制的按键。
 * 通过 conflict prop 接收冲突信息,实时标红。
 * 录制期间通过 setShortcutRecording 暂停全局快捷键监听。
 */
const props = defineProps<{
  modelValue: string[]
  type: 'sequence' | 'combo'
  /** 冲突提示文案(非空时标红)。 */
  conflict?: string
  /** 黑名单提示文案(非空时标红)。 */
  blacklisted?: string
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', val: string[]): void
}>()

const SEQUENCE_TIMEOUT = 500
const MODIFIER_KEYS = new Set(['control', 'meta', 'alt', 'shift'])

const recording = ref(false)
const liveBuffer = ref<string[]>([])

let timer: ReturnType<typeof setTimeout> | null = null

const displayKeys = computed(() => {
  const keys = recording.value ? liveBuffer.value : props.modelValue
  if (keys.length === 0) return ''
  return formatKeys(keys, props.type)
})

const hasError = computed(
  () => !!props.conflict || !!props.blacklisted,
)

function normalizeKey(key: string): string {
  const lower = key.toLowerCase()
  if (lower === ' ') return 'space'
  return lower
}

function startRecording(): void {
  recording.value = true
  liveBuffer.value = []
  setShortcutRecording(true)
}

function stopRecording(): void {
  recording.value = false
  setShortcutRecording(false)
  if (timer) {
    clearTimeout(timer)
    timer = null
  }
  // 录制结束时把缓冲区提交到 modelValue
  if (liveBuffer.value.length > 0) {
    emit('update:modelValue', [...liveBuffer.value])
  }
  liveBuffer.value = []
}

function clearKeys(): void {
  stopRecording()
  emit('update:modelValue', [])
}

function onKeydown(event: KeyboardEvent): void {
  if (!recording.value) return
  event.preventDefault()
  event.stopPropagation()

  const key = normalizeKey(event.key)

  // 纯修饰键按下不单独记录
  if (MODIFIER_KEYS.has(key)) return

  if (props.type === 'combo') {
    // 组合键模式:需要至少一个修饰键
    if (!event.ctrlKey && !event.metaKey && !event.altKey) {
      // 无修饰键时提示但不录制
      return
    }
    const parts: string[] = []
    if (event.ctrlKey) parts.push('ctrl')
    if (event.metaKey) parts.push('win')
    if (event.altKey) parts.push('alt')
    if (event.shiftKey) parts.push('shift')
    parts.push(key)
    const combo = normalizeComboString(parts.join('+'))
    liveBuffer.value = [combo]
    // 组合键录制后立即提交并停止
    emit('update:modelValue', [combo])
    stopRecording()
    return
  }

  // 序列键模式:追加按键到缓冲区
  // 修饰键 + 主键时,只记录主键(序列键不关心修饰键)
  liveBuffer.value = [...liveBuffer.value, key]
  emit('update:modelValue', [...liveBuffer.value])

  // 重置 500ms 超时定时器
  if (timer) clearTimeout(timer)
  timer = setTimeout(() => {
    stopRecording()
  }, SEQUENCE_TIMEOUT)
}

/* 组件卸载时确保恢复全局快捷键监听 */
onMounted(() => {
  window.addEventListener('keydown', onKeydown, { capture: true })
})

onUnmounted(() => {
  window.removeEventListener('keydown', onKeydown, { capture: true })
  if (timer) clearTimeout(timer)
  setShortcutRecording(false)
})

/* 切换 type 时清除已有录制 */
watch(
  () => props.type,
  () => {
    clearKeys()
  },
)
</script>

<template>
  <div class="shortcut-recorder" :class="{ 'has-error': hasError }">
    <div class="recorder-display" :class="{ recording }">
      <span v-if="displayKeys" class="recorder-keys">{{ displayKeys }}</span>
      <span v-else class="recorder-placeholder">
        {{ recording ? '按下按键录制…' : '点击下方按钮开始录制' }}
      </span>
    </div>

    <div class="recorder-actions">
      <d-button
        v-if="!recording"
        size="small"
        type="common"
        @click="startRecording"
      >
        开始录制
      </d-button>
      <d-button
        v-else
        size="small"
        type="primary"
        @click="stopRecording"
      >
        完成录制
      </d-button>
      <d-button
        size="small"
        type="common"
        :disabled="modelValue.length === 0 && !recording"
        @click="clearKeys"
      >
        清除
      </d-button>
    </div>

    <small v-if="blacklisted" class="recorder-error">
      {{ blacklisted }}
    </small>
    <small v-else-if="conflict" class="recorder-error">
      {{ conflict }}
    </small>
    <small v-else-if="type === 'combo'" class="recorder-hint">
      请按住至少一个修饰键(Ctrl / Alt / Shift / Win)再按主键
    </small>
    <small v-else-if="type === 'sequence'" class="recorder-hint">
      连续按下按键,500ms 内无新按键则自动完成
    </small>
  </div>
</template>

<style scoped>
.shortcut-recorder {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.recorder-display {
  min-height: 48px;
  padding: 10px 14px;
  border: 2px dashed #d9d9d9;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f7f8fa;
  transition: border-color 0.2s, background 0.2s;
}

.recorder-display.recording {
  border-color: #1668dc;
  background: #e8f3ff;
  animation: pulse 1.5s ease-in-out infinite;
}

.shortcut-recorder.has-error .recorder-display {
  border-color: #f53f3f;
  background: #ffece8;
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.7; }
}

.recorder-keys {
  font-size: 16px;
  font-weight: 600;
  color: #1c1f23;
  letter-spacing: 1px;
}

.has-error .recorder-keys {
  color: #f53f3f;
}

.recorder-placeholder {
  font-size: 13px;
  color: #86909c;
}

.recorder-actions {
  display: flex;
  gap: 8px;
}

.recorder-error {
  color: #f53f3f;
  font-size: 12px;
  line-height: 1.4;
}

.recorder-hint {
  color: #86909c;
  font-size: 12px;
  line-height: 1.4;
}
</style>
