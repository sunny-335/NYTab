import { defineToolPlugin } from '../types'

export default defineToolPlugin({
  meta: {
    pluginId: 'regex',
    name: '正则测试',
    category: 'developer',
    icon: '🔎',
    description: '正则表达式匹配测试',
    defaultSize: { w: 8, h: 5 },
  },
  component: () => import('./Regex.vue'),
  defaultState: () => ({ pattern: '', flags: 'g', testString: '', preset: '' }),
})
