<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { usePluginState } from '@/composables/usePluginState'

type ExchangeState = {
  from: string
  to: string
  amount: number
  rates: Record<string, number>
  ratesAt: number
}

const CURRENCIES = [
  { name: 'USD', value: 'USD' },
  { name: 'EUR', value: 'EUR' },
  { name: 'CNY', value: 'CNY' },
  { name: 'JPY', value: 'JPY' },
  { name: 'GBP', value: 'GBP' },
  { name: 'KRW', value: 'KRW' },
  { name: 'HKD', value: 'HKD' },
  { name: 'TWD', value: 'TWD' },
  { name: 'AUD', value: 'AUD' },
  { name: 'CAD', value: 'CAD' },
]

const RATES_CACHE_MS = 3600_000

const { state } = usePluginState<ExchangeState>('exchange', {
  defaultState: () => ({
    from: 'USD',
    to: 'CNY',
    amount: 100,
    rates: {},
    ratesAt: 0,
  }),
})

const errorMsg = ref('')
const loading = ref(false)

async function refreshRates() {
  loading.value = true
  errorMsg.value = ''
  try {
    const res = await fetch('https://api.exchangerate-api.com/v4/latest/USD')
    if (!res.ok) throw new Error(`HTTP ${res.status}`)
    const data = (await res.json()) as { rates?: Record<string, number> }
    if (!data.rates) throw new Error('返回数据格式异常')
    if (state.value) {
      state.value.rates = data.rates
      state.value.ratesAt = Date.now()
    }
  } catch (e) {
    errorMsg.value = (e as Error).message || '获取汇率失败'
  } finally {
    loading.value = false
  }
}

// state 加载完成后，若汇率缓存超过 1 小时则刷新
watch(
  () => state.value,
  (s) => {
    if (s && Date.now() - (s.ratesAt || 0) > RATES_CACHE_MS) {
      void refreshRates()
    }
  },
)

const result = computed<number | null>(() => {
  const s = state.value
  if (!s || !s.rates || !s.rates.USD) return null
  const fromRate = s.rates[s.from]
  const toRate = s.rates[s.to]
  if (!fromRate || !toRate) return null
  // 先折算为 USD，再换算为目标货币
  const usdAmount = s.amount / fromRate
  return usdAmount * toRate
})

function swap() {
  if (!state.value) return
  const tmp = state.value.from
  state.value.from = state.value.to
  state.value.to = tmp
}
</script>

<template>
  <div class="exchange">
    <div v-if="state" class="exchange-form">
      <label class="field">
        <span class="field-label">源货币</span>
        <d-select v-model="state.from" :options="CURRENCIES" />
      </label>
      <label class="field">
        <span class="field-label">金额</span>
        <d-input-number v-model="state.amount" :min="0" :step="1" />
      </label>
      <label class="field">
        <span class="field-label">目标货币</span>
        <d-select v-model="state.to" :options="CURRENCIES" />
      </label>
      <div class="actions">
        <d-button size="small" @click="swap">⇅ 反转</d-button>
        <d-button size="small" type="common" :loading="loading" @click="refreshRates">
          刷新汇率
        </d-button>
      </div>
      <div class="result-box">
        <div v-if="errorMsg" class="error">⚠ {{ errorMsg }}</div>
        <div v-else-if="result !== null" class="result-value">
          <span class="result-from">{{ state.amount }} {{ state.from }}</span>
          <span class="result-eq">≈</span>
          <strong class="result-to">{{ result.toFixed(2) }} {{ state.to }}</strong>
        </div>
        <div v-else-if="loading" class="hint">正在获取汇率…</div>
        <div v-else class="hint">点击"刷新汇率"获取最新数据</div>
      </div>
    </div>
    <div v-else class="hint">加载中…</div>
  </div>
</template>

<style scoped>
.exchange {
  display: flex;
  flex-direction: column;
  gap: 10px;
  font-size: 13px;
}

.exchange-form {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.field-label {
  font-size: 12px;
  color: #4e5969;
  font-weight: 500;
}

.actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.result-box {
  margin-top: 4px;
  padding: 10px 12px;
  background: #f7f8fa;
  border-radius: 6px;
  min-height: 40px;
  display: flex;
  align-items: center;
}

.result-value {
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

.error {
  color: #f53f3f;
  font-size: 13px;
}
</style>
