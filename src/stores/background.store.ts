import { defineStore } from 'pinia'
import { ref } from 'vue'
import { backgroundApi } from '@/api/background.api'
import type { BackgroundConfig, BackgroundUpdatePayload } from '@/api/background.api'

/**
 * 背景配置状态。
 *
 * `background` 由 App.vue 中的 <Background /> 组件在挂载时拉取并消费;
 * BackgroundSettings 页面通过 updateBackground / uploadBackground 修改后,
 * 由于 store 是 Pinia 单例,<Background /> 会响应式重渲染。
 *
 * `loaded` 标记用于避免重复拉取(首次成功后跳过后续 fetchBackground),
 * 强制刷新请使用 `reload()`。
 */
export const useBackgroundStore = defineStore('background', () => {
  const background = ref<BackgroundConfig | null>(null)
  const loading = ref(false)
  const loaded = ref(false)

  /** 拉取背景配置(已加载则跳过,避免首页与设置页重复请求)。 */
  async function fetchBackground(): Promise<void> {
    if (loaded.value) return
    loading.value = true
    try {
      background.value = await backgroundApi.getBackground()
      loaded.value = true
    } finally {
      loading.value = false
    }
  }

  /** 强制重新拉取。 */
  async function reload(): Promise<void> {
    loading.value = true
    try {
      background.value = await backgroundApi.getBackground()
      loaded.value = true
    } finally {
      loading.value = false
    }
  }

  /** 更新背景配置并同步本地 state(鉴权)。 */
  async function updateBackground(
    payload: BackgroundUpdatePayload,
  ): Promise<BackgroundConfig> {
    const result = await backgroundApi.updateBackground(payload)
    background.value = result
    loaded.value = true
    return result
  }

  /** 上传背景图片,后端会自动将配置设为 type=image(鉴权)。 */
  async function uploadBackground(file: File): Promise<string> {
    const result = await backgroundApi.uploadBackground(file)
    // 后端已持久化为 type=image,本地乐观同步。
    background.value = {
      type: 'image',
      url: result.url,
      lastUpdate: new Date().toISOString(),
    }
    loaded.value = true
    return result.url
  }

  return {
    background,
    loading,
    loaded,
    fetchBackground,
    reload,
    updateBackground,
    uploadBackground,
  }
})
