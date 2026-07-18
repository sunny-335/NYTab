import { defineStore } from 'pinia'
import { ref } from 'vue'
import { weatherApi } from '@/api/weather.api'
import type {
  WeatherData,
  WeatherSettings,
  CityOption,
} from '@/api/weather.api'

/**
 * 天气状态。
 *
 * - `settings`：天气数据源 / 密钥 / 默认城市 / 自动定位开关。密钥字段后端脱敏。
 * - `currentWeather`：最近一次成功拉取的天气数据，供天气插件直接消费。
 *
 * 设置页（WeatherSettings.vue）通过 fetchSettings / updateSettings 读写配置；
 * 天气插件（Weather.vue）通过 fetchWeather / fetchWeatherByLocation 拉取数据。
 * 由于 store 是 Pinia 单例，设置页保存后插件可立即响应。
 */
export const useWeatherStore = defineStore('weather', () => {
  const settings = ref<WeatherSettings | null>(null)
  const currentWeather = ref<WeatherData | null>(null)
  const loading = ref(false)
  const settingsLoaded = ref(false)

  /** 拉取天气设置（密钥脱敏）。已加载则跳过，避免重复请求。 */
  async function fetchSettings(force = false): Promise<void> {
    if (settingsLoaded.value && !force) return
    loading.value = true
    try {
      settings.value = await weatherApi.getSettings()
      settingsLoaded.value = true
    } finally {
      loading.value = false
    }
  }

  /** 更新天气设置并同步本地 state。 */
  async function updateSettings(
    payload: Partial<WeatherSettings>,
  ): Promise<WeatherSettings> {
    const result = await weatherApi.updateSettings(payload)
    settings.value = result
    settingsLoaded.value = true
    return result
  }

  /** 按城市名获取天气（city 缺省时后端使用 default_city）。 */
  async function fetchWeather(city?: string): Promise<WeatherData> {
    const data = await weatherApi.getWeather(city ? { city } : {})
    currentWeather.value = data
    return data
  }

  /** 按经纬度获取天气（用于自动定位）。 */
  async function fetchWeatherByLocation(
    lat: number,
    lng: number,
  ): Promise<WeatherData> {
    const data = await weatherApi.getWeather({ lat, lng })
    currentWeather.value = data
    return data
  }

  /** 城市搜索（透传给 API，store 不缓存）。 */
  async function searchCities(keyword: string): Promise<CityOption[]> {
    return weatherApi.searchCities(keyword)
  }

  return {
    settings,
    currentWeather,
    loading,
    settingsLoaded,
    fetchSettings,
    updateSettings,
    fetchWeather,
    fetchWeatherByLocation,
    searchCities,
  }
})
