import { defineToolPlugin } from '../types'

export default defineToolPlugin({
  meta: {
    pluginId: 'notes',
    name: '便签',
    category: 'efficiency',
    icon: '🗒️',
    description: '多张便签备忘录',
    defaultSize: { w: 6, h: 4 },
    minSize: { w: 3, h: 2 },
  },
  component: () => import('./Notes.vue'),
  defaultState: () => ({
    items: [
      { id: '1', content: '欢迎使用便签', color: 'yellow' as const },
    ],
  }),
})
