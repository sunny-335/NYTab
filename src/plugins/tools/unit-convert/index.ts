import { defineToolPlugin } from '../types'

export default defineToolPlugin({
  meta: {
    pluginId: 'unit-convert',
    name: '单位换算',
    category: 'lifestyle',
    icon: '📏',
    description: '长度/重量/温度/面积换算',
    defaultSize: { w: 6, h: 4 },
  },
  component: () => import('./UnitConvert.vue'),
  defaultState: () => ({
    category: 'length',
    from: 'm',
    to: 'km',
    value: 1,
  }),
})
