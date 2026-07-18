<script setup lang="ts">
import { computed, watch } from 'vue'
import { usePluginState } from '@/composables/usePluginState'

type Category = 'length' | 'weight' | 'temperature' | 'area'

type UnitConvertState = {
  category: Category
  from: string
  to: string
  value: number
}

interface UnitDef {
  /** 显示名称 */
  name: string
  /** 单位标识 */
  value: string
  /** 换算因子（相对于基准单位） */
  factor: number
}

// 各类别单位表（factor 相对于该类别的基准单位）
const UNITS: Record<Category, UnitDef[]> = {
  length: [
    { name: '米 (m)', value: 'm', factor: 1 },
    { name: '千米 (km)', value: 'km', factor: 1000 },
    { name: '厘米 (cm)', value: 'cm', factor: 0.01 },
    { name: '毫米 (mm)', value: 'mm', factor: 0.001 },
    { name: '英里 (mile)', value: 'mile', factor: 1609.344 },
    { name: '英尺 (ft)', value: 'ft', factor: 0.3048 },
    { name: '英寸 (in)', value: 'in', factor: 0.0254 },
  ],
  weight: [
    { name: '千克 (kg)', value: 'kg', factor: 1 },
    { name: '克 (g)', value: 'g', factor: 0.001 },
    { name: '吨 (t)', value: 't', factor: 1000 },
    { name: '磅 (lb)', value: 'lb', factor: 0.45359237 },
    { name: '盎司 (oz)', value: 'oz', factor: 0.028349523125 },
  ],
  temperature: [
    { name: '摄氏度 (°C)', value: 'C', factor: 1 },
    { name: '华氏度 (°F)', value: 'F', factor: 1 },
    { name: '开尔文 (K)', value: 'K', factor: 1 },
  ],
  area: [
    { name: '平方米 (m²)', value: 'm2', factor: 1 },
    { name: '平方千米 (km²)', value: 'km2', factor: 1_000_000 },
    { name: '平方厘米 (cm²)', value: 'cm2', factor: 0.0001 },
    { name: '英亩 (acre)', value: 'acre', factor: 4046.8564224 },
    { name: '公顷 (ha)', value: 'ha', factor: 10000 },
  ],
}

const CATEGORY_OPTIONS = [
  { name: '长度', value: 'length' },
  { name: '重量', value: 'weight' },
  { name: '温度', value: 'temperature' },
  { name: '面积', value: 'area' },
]

const { state } = usePluginState<UnitConvertState>('unit-convert', {
  defaultState: () => ({
    category: 'length',
    from: 'm',
    to: 'km',
    value: 1,
  }),
})

const unitOptions = computed(() =>
  UNITS[(state.value?.category ?? 'length') as Category].map((u) => ({
    name: u.name,
    value: u.value,
  })),
)

// 切换类别时自动设默认 from/to
watch(
  () => state.value?.category,
  (cat, oldCat) => {
    if (!cat || cat === oldCat || !state.value) return
    const units = UNITS[cat]
    if (units.length >= 2) {
      state.value.from = units[0].value
      state.value.to = units[1].value
    }
  },
)

// 摄氏度转换辅助
function toCelsius(value: number, unit: string): number {
  switch (unit) {
    case 'C':
      return value
    case 'F':
      return ((value - 32) * 5) / 9
    case 'K':
      return value - 273.15
    default:
      return NaN
  }
}

function fromCelsius(c: number, unit: string): number {
  switch (unit) {
    case 'C':
      return c
    case 'F':
      return (c * 9) / 5 + 32
    case 'K':
      return c + 273.15
    default:
      return NaN
  }
}

const result = computed<number | null>(() => {
  const s = state.value
  if (!s) return null
  const units = UNITS[s.category]
  const fromUnit = units.find((u) => u.value === s.from)
  const toUnit = units.find((u) => u.value === s.to)
  if (!fromUnit || !toUnit) return null

  if (s.category === 'temperature') {
    const c = toCelsius(s.value, s.from)
    return fromCelsius(c, s.to)
  }

  // 通用因子换算：先转基准单位，再转目标单位
  const baseValue = s.value * fromUnit.factor
  return baseValue / toUnit.factor
})

function formatNumber(n: number): string {
  if (!isFinite(n)) return '—'
  if (Math.abs(n) >= 1e9 || (Math.abs(n) > 0 && Math.abs(n) < 1e-6)) {
    return n.toExponential(4)
  }
  // 保留最多 6 位小数，去掉尾随 0
  return parseFloat(n.toFixed(6)).toString()
}

function swap() {
  if (!state.value) return
  const tmp = state.value.from
  state.value.from = state.value.to
  state.value.to = tmp
}
</script>

<template>
  <div class="unit-convert">
    <div v-if="state" class="uc-form">
      <label class="field">
        <span class="field-label">类别</span>
        <d-select v-model="state.category" :options="CATEGORY_OPTIONS" />
      </label>

      <div class="row">
        <label class="field field-grow">
          <span class="field-label">数值</span>
          <d-input-number v-model="state.value" :step="1" />
        </label>
        <label class="field field-grow">
          <span class="field-label">源单位</span>
          <d-select v-model="state.from" :options="unitOptions" />
        </label>
      </div>

      <div class="row">
        <label class="field field-grow">
          <span class="field-label">目标单位</span>
          <d-select v-model="state.to" :options="unitOptions" />
        </label>
        <div class="field-actions">
          <d-button size="small" @click="swap">⇅ 反转</d-button>
        </div>
      </div>

      <div class="result-box">
        <span class="result-from">{{ formatNumber(state.value) }} {{ state.from }}</span>
        <span class="result-eq">=</span>
        <strong class="result-to">
          {{ result !== null ? formatNumber(result) : '—' }} {{ state.to }}
        </strong>
      </div>
    </div>
    <div v-else class="hint">加载中…</div>
  </div>
</template>

<style scoped>
.unit-convert {
  display: flex;
  flex-direction: column;
  gap: 10px;
  font-size: 13px;
}

.uc-form {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.row {
  display: flex;
  gap: 10px;
  align-items: flex-end;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.field-grow {
  flex: 1;
  min-width: 0;
}

.field-actions {
  display: flex;
  align-items: flex-end;
  padding-bottom: 0;
}

.field-label {
  font-size: 12px;
  color: #4e5969;
  font-weight: 500;
}

.result-box {
  margin-top: 4px;
  padding: 10px 12px;
  background: #f7f8fa;
  border-radius: 6px;
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  font-size: 14px;
}

.result-from {
  color: #4e5969;
}

.result-eq {
  color: #86909c;
}

.result-to {
  color: #1668dc;
  font-size: 16px;
}

.hint {
  color: #86909c;
  font-size: 13px;
}
</style>
