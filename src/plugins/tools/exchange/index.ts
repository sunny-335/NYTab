import { defineToolPlugin } from '../types'

export default defineToolPlugin({
  meta: {
    pluginId: 'exchange',
    name: '汇率转换',
    category: 'lifestyle',
    icon: '💱',
    description: '常用货币汇率转换',
    defaultSize: { w: 4, h: 4 },
  },
  component: () => import('./Exchange.vue'),
  defaultState: () => ({
    from: 'USD',
    to: 'CNY',
    amount: 100,
    rates: {} as Record<string, number>,
    ratesAt: 0,
  }),
})
