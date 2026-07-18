import { defineToolPlugin } from '../types'

export default defineToolPlugin({
  meta: {
    pluginId: 'clock',
    name: '时钟',
    category: 'efficiency',
    icon: '🕐',
    description: '时钟（支持全屏大字号显示）',
    defaultSize: { w: 4, h: 3 },
    minSize: { w: 3, h: 2 },
  },
  component: () => import('./Clock.vue'),
  // 保留 city / weather / lastWeatherAt 字段以兼容旧数据（天气能力已迁移至 weather 插件）
  defaultState: () => ({
    city: 'Beijing',
    weather: '',
    lastWeatherAt: 0,
  }),
})
