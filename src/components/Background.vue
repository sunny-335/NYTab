<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useBackgroundStore } from '@/stores/background.store'
import { useBreakpoint } from '@/composables/useBreakpoint'

/**
 * Background — 全屏背景层。
 *
 * 根据 store 中的背景配置渲染:
 *   - image: 用上传图片 url 作为 background-image
 *   - api:   用外部 API 链接 url 作为 background-image
 *   - bing:  根据 768px 断点请求 Bing 壁纸(PC / 移动端)
 *
 * 容器 fixed 全屏、z-index: -1,位于所有内容之下,不影响交互。
 * 配置由 store 在 onMounted 时拉取(public 接口,首页加载即可用)。
 */
const store = useBackgroundStore()
const { isMobile } = useBreakpoint()

const BING_BASE = 'https://bing.img.run/api.html'

const bgUrl = computed<string>(() => {
  const cfg = store.background
  if (!cfg) return ''
  if (cfg.type === 'image' || cfg.type === 'api') {
    return cfg.url || ''
  }
  // bing: 按 device 尺寸切换
  const type = isMobile.value ? 'mobile' : 'pc'
  return `${BING_BASE}?type=${type}`
})

const hasBg = computed(() => bgUrl.value !== '')

const bgStyle = computed(() => {
  if (!hasBg.value) return {}
  return {
    backgroundImage: `url("${bgUrl.value}")`,
  }
})

onMounted(() => {
  store.fetchBackground()
})
</script>

<template>
  <div class="app-background" :style="bgStyle" aria-hidden="true"></div>
</template>

<style scoped>
.app-background {
  position: fixed;
  inset: 0;
  width: 100vw;
  height: 100vh;
  background-position: center;
  background-size: cover;
  background-repeat: no-repeat;
  background-color: #f7f8fa;
  z-index: -1;
  pointer-events: none;
}
</style>
