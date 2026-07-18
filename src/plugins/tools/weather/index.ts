import { defineToolPlugin } from '../types'

/**
 * Weather 插件 — 实时天气（高德 / 和风）。
 *
 * 状态字段：
 *  - lastCity：上次使用的城市名（用于跨会话恢复）
 *  - lastWeather：上次获取的天气数据（前端缓存 30 分钟）
 *  - lastUpdate：上次成功更新的时间戳（ms）
 */
export default defineToolPlugin({
  meta: {
    pluginId: 'weather',
    name: '天气',
    category: 'lifestyle',
    icon: '🌤️',
    description: '实时天气（高德 / 和风）',
    defaultSize: { w: 4, h: 3 },
    minSize: { w: 3, h: 2 },
  },
  component: () => import('./Weather.vue'),
  defaultState: () => ({
    lastCity: '',
    lastWeather: null as
      | {
          temp: string
          condition: string
          humidity: string
          wind: string
          city: string
        }
      | null,
    lastUpdate: 0,
  }),
})
