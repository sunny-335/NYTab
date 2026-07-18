<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { usePluginState } from '@/composables/usePluginState'

/**
 * ClockState 仍保留 city / weather / lastWeatherAt 字段以兼容旧数据，
 * 但本组件不再读取或写入它们——天气能力已迁移至独立的 weather 插件。
 */
interface ClockState {
  city: string
  weather: string
  lastWeatherAt: number
  [key: string]: unknown
}

const { state } = usePluginState<ClockState>('clock', {
  defaultState: () => ({ city: 'Beijing', weather: '', lastWeatherAt: 0 }),
})

const now = ref<Date>(new Date())
const clockRef = ref<HTMLElement | null>(null)
const isFullscreen = ref(false)

let tickHandle: number | null = null

const displayTime = computed(
  () =>
    `${String(now.value.getHours()).padStart(2, '0')}:${String(
      now.value.getMinutes(),
    ).padStart(2, '0')}:${String(now.value.getSeconds()).padStart(2, '0')}`,
)

const displayDate = computed(() => {
  const y = now.value.getFullYear()
  const m = String(now.value.getMonth() + 1).padStart(2, '0')
  const d = String(now.value.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
})

const displayWeekday = computed(() => {
  const names = ['周日', '周一', '周二', '周三', '周四', '周五', '周六']
  return names[now.value.getDay()]
})

async function toggleFullscreen(): Promise<void> {
  if (!clockRef.value) return
  if (document.fullscreenElement) {
    await document.exitFullscreen()
  } else {
    await clockRef.value.requestFullscreen()
  }
}

function handleFullscreenChange(): void {
  isFullscreen.value = !!document.fullscreenElement
}

onMounted(() => {
  tickHandle = window.setInterval(() => {
    now.value = new Date()
  }, 1000)
  document.addEventListener('fullscreenchange', handleFullscreenChange)
})

onUnmounted(() => {
  if (tickHandle !== null) {
    window.clearInterval(tickHandle)
    tickHandle = null
  }
  document.removeEventListener('fullscreenchange', handleFullscreenChange)
})
</script>

<template>
  <div
    v-if="state"
    ref="clockRef"
    class="clock"
    :class="{ 'clock-fullscreen': isFullscreen }"
  >
    <div class="clock__time">{{ displayTime }}</div>
    <div class="clock__date">
      <span>{{ displayDate }}</span>
      <span class="clock__weekday">{{ displayWeekday }}</span>
    </div>
    <div class="clock__actions">
      <d-button type="common" @click="toggleFullscreen">
        {{ isFullscreen ? '退出全屏' : '全屏' }}
      </d-button>
    </div>
  </div>
  <div v-else class="clock clock--loading">加载中…</div>
</template>

<style scoped>
.clock {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 12px;
  height: 100%;
  text-align: center;
}

.clock--loading {
  color: #86909c;
  font-size: 13px;
}

.clock__time {
  font-size: 40px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  color: #1c1f23;
  line-height: 1.1;
  letter-spacing: 1px;
}

.clock__date {
  font-size: 13px;
  color: #4e5969;
  display: flex;
  align-items: center;
  gap: 8px;
}

.clock__weekday {
  padding: 1px 8px;
  border-radius: 999px;
  background: #e8f3ff;
  color: #1668dc;
  font-size: 12px;
}

.clock__actions {
  margin-top: 4px;
  display: flex;
  gap: 8px;
}

/* 全屏态样式：纯黑背景 + 大字号白色时钟，flex 居中填满屏幕 */
.clock-fullscreen {
  background: #000;
  color: #fff;
  gap: 16px;
}

.clock-fullscreen .clock__time {
  font-size: 20vw;
  color: #fff;
  letter-spacing: 2px;
}

.clock-fullscreen .clock__date {
  font-size: 3vw;
  color: #fff;
}

.clock-fullscreen .clock__weekday {
  background: rgba(255, 255, 255, 0.15);
  color: #fff;
}
</style>
