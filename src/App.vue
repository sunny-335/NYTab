<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import DefaultLayout from '@/layouts/DefaultLayout.vue'
import AuthLayout from '@/layouts/AuthLayout.vue'
import Background from '@/components/Background.vue'
import DevModeBanner from '@/components/DevModeBanner.vue'

const route = useRoute()

/** Pick the layout shell based on the matched route's meta.layout. */
const layoutComponent = computed(() =>
  route.meta.layout === 'auth' ? AuthLayout : DefaultLayout,
)
</script>

<template>
  <div class="app-root">
    <!-- 全屏背景层(z-index: -1),位于所有内容之下,不影响交互 -->
    <Background />
    <!-- 开发者模式提示条(仅 dev 模式开启 + 已登录时显示) -->
    <DevModeBanner />
    <component :is="layoutComponent">
      <router-view />
    </component>
  </div>
</template>

<style scoped>
.app-root {
  min-height: 100vh;
}
</style>
