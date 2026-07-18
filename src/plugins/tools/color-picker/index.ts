import { defineToolPlugin } from '../types'

export default defineToolPlugin({
  meta: {
    pluginId: 'color-picker',
    name: '颜色拾取器',
    category: 'developer',
    icon: '🎨',
    description: 'HEX/RGB/HSL 颜色转换',
    defaultSize: { w: 4, h: 4 },
  },
  component: () => import('./ColorPicker.vue'),
  defaultState: () => ({ hex: '#3b82f6' }),
})
