import { defineToolPlugin } from '../types'

export default defineToolPlugin({
  meta: {
    pluginId: 'base64',
    name: 'Base64 编解码',
    category: 'developer',
    icon: '🔐',
    description: 'Base64 编码/解码（UTF-8）',
    defaultSize: { w: 6, h: 4 },
  },
  component: () => import('./Base64.vue'),
  defaultState: () => ({ mode: 'encode', input: '' }),
})
