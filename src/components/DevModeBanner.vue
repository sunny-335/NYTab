<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth.store'
import { devModeApi } from '@/api/dev-mode.api'

/**
 * DevModeBanner — 开发者模式提示条。
 *
 * 仅当满足以下条件时显示:
 *   1. 用户已登录(/api/dev-mode/status 需鉴权)
 *   2. 后端返回 enabled === true
 *   3. 本次会话未被用户手动关闭
 *
 * 渲染为顶部橙色提示条,文案固定,可点击 × 关闭(本次会话不再显示)。
 * 由于该接口需鉴权,在登录页 / 安装向导页未登录状态下不会调用,因此也不显示。
 */
const authStore = useAuthStore()
const route = useRoute()

const enabled = ref(false)
const dismissed = ref(false)

/** sessionStorage key:本次会话内是否已关闭提示条。 */
const SESSION_KEY = 'nytab_devmode_banner_dismissed'

/** 仅在已登录状态下显示。 */
const visible = computed(
  () => enabled.value && !dismissed.value && authStore.isAuthenticated,
)

async function fetchStatus() {
  if (!authStore.isAuthenticated) {
    enabled.value = false
    return
  }
  try {
    const status = await devModeApi.status()
    enabled.value = status.enabled
  } catch {
    // 拦截器已 toast;静默不显示提示条
    enabled.value = false
  }
}

function dismiss() {
  dismissed.value = true
  try {
    sessionStorage.setItem(SESSION_KEY, '1')
  } catch {
    // sessionStorage 不可用时仅内存忽略
  }
}

function loadDismissed() {
  try {
    dismissed.value = sessionStorage.getItem(SESSION_KEY) === '1'
  } catch {
    dismissed.value = false
  }
}

onMounted(() => {
  loadDismissed()
  void fetchStatus()
})

// 登录态变化后(登录 / 退出)重新拉取状态。
watch(
  () => authStore.isAuthenticated,
  () => {
    void fetchStatus()
  },
)

// 路由切换时若用户已登录但状态未知,补一次拉取(轻量,接口可缓存)。
watch(
  () => route.path,
  () => {
    if (authStore.isAuthenticated && !enabled.value && !dismissed.value) {
      void fetchStatus()
    }
  },
)
</script>

<template>
  <div v-if="visible" class="devmode-banner" role="alert">
    <span class="devmode-banner__icon">⚠</span>
    <span class="devmode-banner__text">
      当前为开发环境,数据未持久化到生产数据库,如需生产环境使用请关闭开发者模式
    </span>
    <button
      type="button"
      class="devmode-banner__close"
      aria-label="关闭提示"
      @click="dismiss"
    >
      ×
    </button>
  </div>
</template>

<style scoped>
.devmode-banner {
  position: sticky;
  top: 0;
  z-index: 200;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 16px;
  background: #ff7d00;
  color: #fff;
  font-size: 13px;
  line-height: 1.5;
  box-shadow: 0 1px 3px rgba(255, 125, 0, 0.25);
}

.devmode-banner__icon {
  font-size: 14px;
  flex-shrink: 0;
}

.devmode-banner__text {
  flex: 1;
  min-width: 0;
}

.devmode-banner__close {
  flex-shrink: 0;
  width: 22px;
  height: 22px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: transparent;
  border: none;
  color: #fff;
  font-size: 18px;
  line-height: 1;
  cursor: pointer;
  border-radius: 4px;
  transition: background 0.15s;
}

.devmode-banner__close:hover {
  background: rgba(255, 255, 255, 0.2);
}
</style>
