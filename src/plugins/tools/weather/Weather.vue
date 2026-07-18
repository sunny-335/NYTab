<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { usePluginState } from '@/composables/usePluginState'
import { useWeatherStore } from '@/stores/weather.store'
import type { WeatherData } from '@/api/weather.api'

/**
 * Weather 插件组件。
 *
 * - 优先使用插件 state 中缓存的天气数据（30 分钟内有效）。
 * - state.lastUpdate 超过 30 分钟则触发刷新。
 * - 刷新时优先用 settings.default_city（通过 weather store 读取），
 *   或使用 state.lastCity 兜底。
 * - 无数据时提示「请在设置中配置天气」。
 */

interface WeatherState {
  lastCity: string
  lastWeather: WeatherData | null
  lastUpdate: number
  [key: string]: unknown
}

/** 前端缓存有效期 30 分钟。 */
const CACHE_MS = 30 * 60 * 1000

const { state, patch } = usePluginState<WeatherState>('weather', {
  defaultState: () => ({
    lastCity: '',
    lastWeather: null,
    lastUpdate: 0,
  }),
})

const weatherStore = useWeatherStore()

const loading = ref(false)
const errorMsg = ref('')

const weather = computed<WeatherData | null>(
  () => state.value?.lastWeather ?? null,
)

/** 根据 condition 文本映射一个 emoji 图标。 */
const weatherIcon = computed<string>(() => {
  const c = (weather.value?.condition || '').toLowerCase()
  if (!c) return '🌡️'
  if (c.includes('雷')) return '⛈️'
  if (c.includes('雨')) return c.includes('雪') ? '🌨️' : '🌧️'
  if (c.includes('雪') || c.includes('冰')) return '❄️'
  if (c.includes('雾') || c.includes('霾')) return '🌫️'
  if (c.includes('云') || c.includes('阴')) return '☁️'
  if (c.includes('晴')) return '☀️'
  return '🌤️'
})

const isStale = computed(() => {
  const ts = state.value?.lastUpdate ?? 0
  return Date.now() - ts > CACHE_MS
})

/** 拉取天气并写入插件 state。 */
async function refreshWeather(): Promise<void> {
  loading.value = true
  errorMsg.value = ''
  try {
    // 确保设置已加载，以便拿到 default_city
    await weatherStore.fetchSettings()
    const city =
      state.value?.lastCity ||
      weatherStore.settings?.default_city ||
      undefined
    const data = await weatherStore.fetchWeather(city)
    patch({
      lastCity: data.city,
      lastWeather: data,
      lastUpdate: Date.now(),
    })
  } catch (e) {
    errorMsg.value = e instanceof Error ? e.message : '获取天气失败'
  } finally {
    loading.value = false
  }
}

// state 加载完成后，若缓存过期则刷新
watch(
  () => state.value,
  (s) => {
    if (!s) return
    if (!s.lastWeather || Date.now() - (s.lastUpdate || 0) > CACHE_MS) {
      void refreshWeather()
    }
  },
)

onMounted(() => {
  // 若 state 已由 usePluginState 同步赋值（极少见），兜底触发一次检查
  if (state.value && isStale.value && !loading.value) {
    void refreshWeather()
  }
})
</script>

<template>
  <div class="weather">
    <div v-if="weather" class="weather__main">
      <div class="weather__icon">{{ weatherIcon }}</div>
      <div class="weather__body">
        <div class="weather__temp">{{ weather.temp }}°</div>
        <div class="weather__condition">{{ weather.condition }}</div>
        <div class="weather__city">{{ weather.city }}</div>
      </div>
      <div class="weather__meta">
        <div class="weather__meta-item">
          <span class="weather__meta-label">湿度</span>
          <span class="weather__meta-value">{{ weather.humidity }}</span>
        </div>
        <div class="weather__meta-item">
          <span class="weather__meta-label">风力</span>
          <span class="weather__meta-value">{{ weather.wind }}</span>
        </div>
      </div>
    </div>

    <div v-else-if="loading" class="weather__placeholder">加载中…</div>

    <div v-else-if="errorMsg" class="weather__placeholder weather__placeholder--error">
      <div>⚠ {{ errorMsg }}</div>
      <d-button size="small" type="common" @click="refreshWeather">重试</d-button>
    </div>

    <div v-else class="weather__placeholder">请在设置中配置天气</div>

    <div v-if="weather" class="weather__actions">
      <d-button
        size="small"
        type="common"
        :loading="loading"
        @click="refreshWeather"
      >
        刷新
      </d-button>
    </div>
  </div>
</template>

<style scoped>
.weather {
  display: flex;
  flex-direction: column;
  height: 100%;
  padding: 12px;
  gap: 8px;
}

.weather__main {
  display: flex;
  align-items: center;
  gap: 12px;
  flex: 1;
}

.weather__icon {
  font-size: 40px;
  line-height: 1;
  flex-shrink: 0;
}

.weather__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.weather__temp {
  font-size: 32px;
  font-weight: 700;
  color: #1c1f23;
  line-height: 1.1;
  font-variant-numeric: tabular-nums;
}

.weather__condition {
  font-size: 13px;
  color: #4e5969;
}

.weather__city {
  font-size: 12px;
  color: #86909c;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.weather__meta {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 11px;
  color: #86909c;
  text-align: right;
  flex-shrink: 0;
}

.weather__meta-item {
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.weather__meta-label {
  color: #c9cdd4;
}

.weather__meta-value {
  color: #4e5969;
  font-weight: 500;
}

.weather__placeholder {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: #86909c;
  font-size: 13px;
  text-align: center;
}

.weather__placeholder--error {
  color: #f53f3f;
}

.weather__actions {
  display: flex;
  justify-content: flex-end;
}
</style>
