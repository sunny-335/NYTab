import { defineToolPlugin } from '../types'

export default defineToolPlugin({
  meta: {
    pluginId: 'code-format',
    name: '代码格式化',
    category: 'developer',
    icon: '🔧',
    description: 'JS/CSS/HTML/JSON 格式化',
    defaultSize: { w: 6, h: 5 },
    minSize: { w: 4, h: 3 },
  },
  component: () => import('./CodeFormat.vue'),
  defaultState: () => ({ language: 'json', input: '' }),
})
