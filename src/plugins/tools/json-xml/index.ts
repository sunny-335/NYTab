import { defineToolPlugin } from '../types'

export default defineToolPlugin({
  meta: {
    pluginId: 'json-xml',
    name: 'JSON/XML 转换',
    category: 'developer',
    icon: '🔄',
    description: 'JSON ↔ XML 双向转换',
    defaultSize: { w: 6, h: 5 },
  },
  component: () => import('./JsonXml.vue'),
  defaultState: () => ({ direction: 'json2xml', input: '' }),
})
