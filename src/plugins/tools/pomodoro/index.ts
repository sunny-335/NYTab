import { defineToolPlugin } from '../types'

export default defineToolPlugin({
  meta: {
    pluginId: 'pomodoro',
    name: '番茄钟',
    category: 'efficiency',
    icon: '🍅',
    description: '25 分钟专注计时器',
    defaultSize: { w: 4, h: 3 },
    minSize: { w: 3, h: 2 },
  },
  component: () => import('./Pomodoro.vue'),
  defaultState: () => ({
    phase: 'work' as 'work' | 'shortBreak' | 'longBreak',
    remaining: 25 * 60,
    running: false,
    todayCount: 0,
    roundCount: 0,
    lastDate: new Date().toISOString().slice(0, 10),
  }),
})
