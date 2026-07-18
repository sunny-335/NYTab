import { defineToolPlugin } from '../types'

export default defineToolPlugin({
  meta: {
    pluginId: 'qrcode',
    name: '二维码生成',
    category: 'lifestyle',
    icon: '📱',
    description: '文本/URL 生成二维码',
    defaultSize: { w: 4, h: 4 },
  },
  component: () => import('./QRCode.vue'),
  defaultState: () => ({
    text: '',
    size: 256,
  }),
})
