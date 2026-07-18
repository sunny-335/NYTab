<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { Message } from 'vue-devui'
import { debounce } from 'lodash-es'
import { useWeatherStore } from '@/stores/weather.store'
import type { WeatherProvider, CityOption } from '@/api/weather.api'

/**
 * WeatherSettings — 天气设置页。
 *
 * - 数据源：高德 / 和风（d-radio-group）
 * - 密钥：输入框显示脱敏值（placeholder），用户输入新值才更新
 * - 默认城市：搜索框 + 下拉，调 /api/weather/cities?keyword=
 * - 自动定位：默认关闭，开启时弹 d-modal 二次确认，调用浏览器定位
 * - 保存按钮：仅提交有变更的字段（密钥为空则不提交，后端保留原值）
 */
const store = useWeatherStore()

/* ----------------------------- 表单状态 ----------------------------- */
const formProvider = ref<WeatherProvider>('gaode')
/** 密钥输入框为空表示「保留原值」，仅当用户输入新值时才提交。 */
const formGaodeKey = ref('')
const formHefengKey = ref('')
const formDefaultCity = ref('')
const formAutoLocation = ref(false)

const saving = ref(false)

/* ----------------------------- 城市搜索 ----------------------------- */
const cityKeyword = ref('')
const cityResults = ref<CityOption[]>([])
const cityDropdownOpen = ref(false)
const citySearching = ref(false)

/** 防抖搜索城市。 */
const debouncedSearch = debounce(async (keyword: string) => {
  const q = keyword.trim()
  if (!q) {
    cityResults.value = []
    cityDropdownOpen.value = false
    return
  }
  citySearching.value = true
  try {
    const list = await store.searchCities(q)
    cityResults.value = list
    cityDropdownOpen.value = list.length > 0
  } catch {
    // 拦截器已 toast
    cityResults.value = []
    cityDropdownOpen.value = false
  } finally {
    citySearching.value = false
  }
}, 350)

watch(cityKeyword, (val) => {
  void debouncedSearch(val)
})

function onCityFocus(): void {
  if (cityResults.value.length > 0) {
    cityDropdownOpen.value = true
  }
}

/** 失焦延迟关闭下拉，避免点击选项前下拉消失。 */
function onCityBlur(): void {
  setTimeout(() => {
    cityDropdownOpen.value = false
  }, 200)
}

function selectCity(opt: CityOption): void {
  formDefaultCity.value = opt.name
  cityKeyword.value = `${opt.province} · ${opt.name}`
  cityDropdownOpen.value = false
}

function clearCity(): void {
  formDefaultCity.value = ''
  cityKeyword.value = ''
  cityResults.value = []
  cityDropdownOpen.value = false
}

/* ----------------------------- 自动定位 ----------------------------- */
const autoLocModalVisible = ref(false)
const locating = ref(false)

/**
 * d-switch 变化拦截：
 *  - 开启 → 弹二次确认 modal
 *  - 关闭 → 直接生效（无需确认）
 */
function onAutoLocationToggle(val: string | number | boolean): void {
  if (val === true || val === 'true') {
    autoLocModalVisible.value = true
  } else {
    formAutoLocation.value = false
  }
}

function cancelAutoLocation(): void {
  autoLocModalVisible.value = false
  // 保持 formAutoLocation = false（switch 由 model-value 驱动回滚）
}

/** 确认启用自动定位：调用浏览器定位 → 反查城市 → 切换。 */
async function confirmAutoLocation(): Promise<void> {
  if (!navigator.geolocation) {
    Message.error('当前浏览器不支持定位功能')
    autoLocModalVisible.value = false
    return
  }

  locating.value = true
  try {
    const position = await new Promise<GeolocationPosition>(
      (resolve, reject) => {
        navigator.geolocation.getCurrentPosition(resolve, reject, {
          enableHighAccuracy: false,
          timeout: 10000,
          maximumAge: 60000,
        })
      },
    )

    const lat = position.coords.latitude
    const lng = position.coords.longitude

    // 调 /api/weather?lat=&lng= → 反查城市
    const data = await store.fetchWeatherByLocation(lat, lng)
    formDefaultCity.value = data.city
    cityKeyword.value = data.city
    formAutoLocation.value = true
    autoLocModalVisible.value = false
    Message.success(`已定位到：${data.city}`)
  } catch (e) {
    const err = e as GeolocationPositionError
    let msg = '定位失败，已保持手动选择城市'
    if (err?.code === 1) {
      msg = '已拒绝定位权限，保持手动选择城市'
    } else if (err?.code === 2) {
      msg = '无法获取位置信息，保持手动选择城市'
    } else if (err?.code === 3) {
      msg = '定位超时，保持手动选择城市'
    }
    Message.warning(msg)
    autoLocModalVisible.value = false
    // formAutoLocation 保持 false
  } finally {
    locating.value = false
  }
}

/* ----------------------------- 数据同步 ----------------------------- */
function syncFromStore(): void {
  const s = store.settings
  if (!s) return
  formProvider.value = s.provider
  formGaodeKey.value = ''
  formHefengKey.value = ''
  formDefaultCity.value = s.default_city
  cityKeyword.value = s.default_city
  formAutoLocation.value = s.auto_location
}

/** 当前数据源对应的脱敏密钥（用作 placeholder）。 */
const maskedKey = computed<string>(() => {
  const s = store.settings
  if (!s) return ''
  return formProvider.value === 'hefeng' ? s.hefeng_key : s.gaode_key
})

const canSave = computed(() => !saving.value && !locating.value)

/**
 * 保存设置：仅提交有变更的字段。
 * 密钥输入框为空时不提交该字段（后端保留原值）。
 */
async function handleSave(): Promise<void> {
  if (!canSave.value) return
  saving.value = true
  try {
    const payload: Record<string, unknown> = {
      provider: formProvider.value,
      default_city: formDefaultCity.value,
      auto_location: formAutoLocation.value,
    }
    // 密钥仅在用户输入新值时提交，空值跳过（后端保留原密钥）
    if (formProvider.value === 'gaode' && formGaodeKey.value.trim()) {
      payload.gaode_key = formGaodeKey.value.trim()
    }
    if (formProvider.value === 'hefeng' && formHefengKey.value.trim()) {
      payload.hefeng_key = formHefengKey.value.trim()
    }
    await store.updateSettings(payload)
    syncFromStore()
    Message.success('天气设置已保存')
  } catch {
    // 拦截器已 toast
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await store.fetchSettings()
  syncFromStore()
})

onBeforeUnmount(() => {
  debouncedSearch.cancel()
})
</script>

<template>
  <div class="weather-settings">
    <h1 class="page-title">天气设置</h1>

    <!-- 数据源 -->
    <section class="settings-card">
      <h2 class="card-title">数据源</h2>
      <p class="card-desc">选择天气数据提供商，需配置对应的 API 密钥。</p>
      <d-radio-group v-model="formProvider" direction="row" class="provider-group">
        <d-radio value="gaode">高德天气</d-radio>
        <d-radio value="hefeng">和风天气</d-radio>
      </d-radio-group>

      <label class="form-field">
        <span class="form-label">
          {{ formProvider === 'hefeng' ? '和风' : '高德' }} API 密钥
        </span>
        <d-input
          v-if="formProvider === 'hefeng'"
          v-model="formHefengKey"
          type="password"
          :placeholder="maskedKey ? `当前：${maskedKey}（留空则不修改）` : '请输入和风 API 密钥'"
        />
        <d-input
          v-else
          v-model="formGaodeKey"
          type="password"
          :placeholder="maskedKey ? `当前：${maskedKey}（留空则不修改）` : '请输入高德 API 密钥'"
        />
        <small class="form-tip">
          显示的是脱敏后的密钥。留空保存将保留原密钥，输入新值才会更新。
        </small>
      </label>
    </section>

    <!-- 默认城市 -->
    <section class="settings-card">
      <h2 class="card-title">默认城市</h2>
      <p class="card-desc">搜索并选择城市作为天气插件的默认地区。</p>

      <div class="city-search">
        <d-input
          v-model="cityKeyword"
          placeholder="输入城市名搜索"
          @focus="onCityFocus"
          @blur="onCityBlur"
        />
        <button
          v-if="cityKeyword"
          type="button"
          class="city-clear"
          @mousedown.prevent="clearCity"
        >
          ×
        </button>

        <div v-if="cityDropdownOpen" class="city-dropdown">
          <div v-if="citySearching" class="city-dropdown__hint">搜索中…</div>
          <div v-else-if="cityResults.length === 0" class="city-dropdown__hint">
            无匹配城市
          </div>
          <button
            v-for="opt in cityResults"
            :key="opt.adcode"
            type="button"
            class="city-dropdown__item"
            @mousedown.prevent="selectCity(opt)"
          >
            <span class="city-dropdown__province">{{ opt.province }}</span>
            <span class="city-dropdown__name">{{ opt.name }}</span>
          </button>
        </div>
      </div>

      <div v-if="formDefaultCity" class="city-current">
        当前默认城市：<strong>{{ formDefaultCity }}</strong>
      </div>
    </section>

    <!-- 自动定位 -->
    <section class="settings-card">
      <h2 class="card-title">自动定位</h2>
      <div class="auto-loc">
        <div class="auto-loc__info">
          <div class="auto-loc__label">根据当前位置自动切换地区</div>
          <div class="auto-loc__desc">
            开启后将请求浏览器位置服务，需要您手动同意。启用后会根据您的当前位置自动切换地区。
          </div>
        </div>
        <d-switch
          :model-value="formAutoLocation"
          @update:model-value="onAutoLocationToggle"
        />
      </div>
    </section>

    <!-- 保存按钮 -->
    <div class="actions">
      <d-button
        type="primary"
        :loading="saving"
        :disabled="!canSave"
        @click="handleSave"
      >
        保存
      </d-button>
    </div>

    <!-- 自动定位确认弹窗 -->
    <d-modal
      :model-value="autoLocModalVisible"
      title="启用自动定位"
      show-close
      show-overlay
      append-to-body
      @update:model-value="autoLocModalVisible = $event"
    >
      <div class="auto-loc-modal">
        <p>
          将请求浏览器位置服务，需要您手动同意。启用后会根据您的当前位置自动切换地区。
        </p>
      </div>
      <template #footer>
        <d-button type="common" @click="cancelAutoLocation">取消</d-button>
        <d-button type="primary" :loading="locating" @click="confirmAutoLocation">
          确认启用
        </d-button>
      </template>
    </d-modal>
  </div>
</template>

<style scoped>
.weather-settings {
  max-width: 720px;
}

.page-title {
  margin: 0 0 24px;
  font-size: 22px;
  font-weight: 600;
}

.settings-card {
  background: #fff;
  border: 1px solid #e5e6eb;
  border-radius: 8px;
  padding: 24px;
  margin-bottom: 20px;
}

.card-title {
  margin: 0 0 8px;
  font-size: 16px;
  font-weight: 600;
}

.card-desc {
  margin: 0 0 16px;
  font-size: 13px;
  color: #86909c;
  line-height: 1.5;
}

.provider-group {
  margin-bottom: 20px;
}

.form-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  width: 100%;
  max-width: 420px;
}

.form-label {
  font-size: 13px;
  color: #4e5969;
  font-weight: 500;
}

.form-tip {
  color: #86909c;
  font-size: 12px;
}

/* 城市搜索 */
.city-search {
  position: relative;
  max-width: 420px;
}

.city-clear {
  position: absolute;
  right: 8px;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  font-size: 18px;
  color: #c9cdd4;
  cursor: pointer;
  padding: 0 4px;
  line-height: 1;
}

.city-clear:hover {
  color: #86909c;
}

.city-dropdown {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  right: 0;
  max-height: 240px;
  overflow-y: auto;
  background: #fff;
  border: 1px solid #e5e6eb;
  border-radius: 6px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
  z-index: 10;
}

.city-dropdown__hint {
  padding: 12px;
  font-size: 13px;
  color: #86909c;
  text-align: center;
}

.city-dropdown__item {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 8px 12px;
  background: none;
  border: none;
  text-align: left;
  cursor: pointer;
  font-size: 13px;
  color: #4e5969;
}

.city-dropdown__item:hover {
  background: #f2f3f5;
}

.city-dropdown__province {
  color: #86909c;
  font-size: 12px;
  flex-shrink: 0;
}

.city-dropdown__name {
  color: #1c1f23;
  font-weight: 500;
}

.city-current {
  margin-top: 12px;
  font-size: 13px;
  color: #4e5969;
}

.city-current strong {
  color: #1668dc;
}

/* 自动定位 */
.auto-loc {
  display: flex;
  align-items: center;
  gap: 16px;
  justify-content: space-between;
}

.auto-loc__info {
  flex: 1;
}

.auto-loc__label {
  font-size: 14px;
  font-weight: 500;
  color: #1c1f23;
  margin-bottom: 4px;
}

.auto-loc__desc {
  font-size: 12px;
  color: #86909c;
  line-height: 1.5;
}

.actions {
  margin-top: 8px;
}

.auto-loc-modal p {
  margin: 0;
  font-size: 14px;
  color: #4e5969;
  line-height: 1.6;
}
</style>
