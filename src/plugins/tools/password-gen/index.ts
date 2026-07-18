import { defineToolPlugin } from '../types'

export default defineToolPlugin({
  meta: {
    pluginId: 'password-gen',
    name: '密码生成器',
    category: 'lifestyle',
    icon: '🔑',
    description: '随机密码生成 + 强度评估',
    defaultSize: { w: 4, h: 4 },
  },
  component: () => import('./PasswordGen.vue'),
  defaultState: () => ({
    length: 16,
    upper: true,
    lower: true,
    digit: true,
    symbol: true,
    excludeAmbiguous: false,
    lastPassword: '',
  }),
})
