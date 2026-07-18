<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { usePluginState } from '@/composables/usePluginState'

interface PomodoroState {
  phase: 'work' | 'shortBreak' | 'longBreak'
  remaining: number
  running: boolean
  todayCount: number
  roundCount: number
  lastDate: string
  [key: string]: unknown
}

const DURATIONS: Record<PomodoroState['phase'], number> = {
  work: 25 * 60,
  shortBreak: 5 * 60,
  longBreak: 15 * 60,
}

const PHASE_LABELS: Record<PomodoroState['phase'], string> = {
  work: '专注',
  shortBreak: '短休',
  longBreak: '长休',
}

const PHASE_COLORS: Record<PomodoroState['phase'], string> = {
  work: '#1668dc',
  shortBreak: '#00b42a',
  longBreak: '#722ed1',
}

const { state, patch } = usePluginState<PomodoroState>('pomodoro', {
  defaultState: () => ({
    phase: 'work',
    remaining: DURATIONS.work,
    running: false,
    todayCount: 0,
    roundCount: 0,
    lastDate: new Date().toISOString().slice(0, 10),
  }),
})

const tickTimer = ref<number | null>(null)

const phase = computed<PomodoroState['phase']>(() => state.value?.phase ?? 'work')
const remaining = computed<number>(() => state.value?.remaining ?? DURATIONS.work)
const running = computed<boolean>(() => state.value?.running ?? false)
const todayCount = computed<number>(() => state.value?.todayCount ?? 0)

const minutes = computed(() => Math.floor(remaining.value / 60))
const seconds = computed(() => remaining.value % 60)
const display = computed(
  () => `${String(minutes.value).padStart(2, '0')}:${String(seconds.value).padStart(2, '0')}`,
)

const phaseLabel = computed(() => PHASE_LABELS[phase.value])
const phaseColor = computed(() => PHASE_COLORS[phase.value])

function todayStr(): string {
  return new Date().toISOString().slice(0, 10)
}

/** 跨日期重置：若上次记录日期与今天不同，重置 todayCount 与 lastDate。 */
function checkDateRoll(): void {
  if (!state.value) return
  if (state.value.lastDate !== todayStr()) {
    patch({ todayCount: 0, lastDate: todayStr() })
  }
}

function clearTimer(): void {
  if (tickTimer.value !== null) {
    window.clearInterval(tickTimer.value)
    tickTimer.value = null
  }
}

/** 当前阶段倒计时归零时的阶段切换逻辑。 */
function handlePhaseComplete(): void {
  const current = phase.value
  if (current === 'work') {
    const completedToday = (state.value?.todayCount ?? 0) + 1
    const completedRound = (state.value?.roundCount ?? 0) + 1
    const next: PomodoroState['phase'] =
      completedRound % 4 === 0 ? 'longBreak' : 'shortBreak'
    patch({
      todayCount: completedToday,
      roundCount: completedRound,
      running: false,
      phase: next,
      remaining: DURATIONS[next],
    })
  } else {
    patch({
      phase: 'work',
      remaining: DURATIONS.work,
      running: false,
    })
  }
}

function startTimer(): void {
  if (running.value) return
  patch({ running: true })
  tickTimer.value = window.setInterval(() => {
    if (!state.value) return
    const next = state.value.remaining - 1
    if (next <= 0) {
      patch({ remaining: 0 })
      clearTimer()
      handlePhaseComplete()
    } else {
      patch({ remaining: next })
    }
  }, 1000)
}

function pauseTimer(): void {
  clearTimer()
  patch({ running: false })
}

function toggle(): void {
  if (running.value) pauseTimer()
  else startTimer()
}

function reset(): void {
  clearTimer()
  patch({ running: false, phase: 'work', remaining: DURATIONS.work })
}

function skip(): void {
  clearTimer()
  handlePhaseComplete()
}

watch(running, (now, prev) => {
  // 状态由外部（如 reload）变更为 true 时，需补齐 interval
  if (now && !prev && tickTimer.value === null) {
    startTimer()
  } else if (!now && prev) {
    clearTimer()
  }
})

onMounted(() => {
  checkDateRoll()
  if (running.value) startTimer()
})

onUnmounted(() => {
  clearTimer()
})
</script>

<template>
  <div v-if="state" class="pomodoro" :style="{ '--phase-color': phaseColor }">
    <div class="pomodoro__phase">
      <span class="pomodoro__phase-badge">{{ phaseLabel }}</span>
    </div>
    <div class="pomodoro__time">{{ display }}</div>
    <div class="pomodoro__count">今日完成 {{ todayCount }} 个番茄</div>
    <div class="pomodoro__actions">
      <d-button type="primary" @click="toggle">
        {{ running ? '暂停' : '开始' }}
      </d-button>
      <d-button type="common" @click="reset">重置</d-button>
      <d-button type="common" @click="skip">跳过</d-button>
    </div>
  </div>
  <div v-else class="pomodoro pomodoro--loading">加载中…</div>
</template>

<style scoped>
.pomodoro {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 12px;
  height: 100%;
  text-align: center;
}

.pomodoro--loading {
  color: #86909c;
  font-size: 13px;
}

.pomodoro__phase {
  display: flex;
  align-items: center;
  gap: 6px;
}

.pomodoro__phase-badge {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  color: #fff;
  background: var(--phase-color, #1668dc);
}

.pomodoro__time {
  font-size: 44px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  color: var(--phase-color, #1668dc);
  line-height: 1.1;
  letter-spacing: 1px;
}

.pomodoro__count {
  font-size: 12px;
  color: #86909c;
}

.pomodoro__actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  justify-content: center;
}
</style>
