<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth.store'
import { useShortcutsStore } from '@/stores/shortcuts.store'
import { useShortcuts } from '@/composables/useShortcuts'
import { useBranding } from '@/composables/useBranding'

const router = useRouter()
const authStore = useAuthStore()
const shortcutsStore = useShortcutsStore()
const { nickname, logo } = useBranding()

const menuOpen = ref(false)

function toggleMenu() {
  menuOpen.value = !menuOpen.value
}

function closeMenu() {
  menuOpen.value = false
}

async function handleLogout() {
  closeMenu()
  await authStore.logout()
  router.push({ name: 'login' })
}

function onClickOutside(e: MouseEvent) {
  const el = e.target as HTMLElement
  if (!el.closest('.user-dropdown')) {
    menuOpen.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', onClickOutside)
  shortcutsStore.init()
})

onUnmounted(() => document.removeEventListener('click', onClickOutside))

// 注册全局快捷键监听器(在 DefaultLayout 内调用,认证页面才生效)
useShortcuts()
</script>

<template>
  <div class="layout">
    <header class="layout-header">
      <div class="header-inner">
        <RouterLink to="/" class="logo">
          <img
            v-if="logo"
            class="logo__img"
            :src="logo"
            :alt="nickname"
            referrerpolicy="no-referrer"
            @error="(e: Event) => ((e.target as HTMLImageElement).style.display = 'none')"
          />
          <span class="logo__text">{{ nickname }}</span>
        </RouterLink>

        <nav class="nav-menu">
          <RouterLink to="/" exact-active-class="active">首页</RouterLink>
          <RouterLink to="/bookmarks" active-class="active">书签</RouterLink>
        </nav>

        <div class="user-dropdown">
          <button class="user-trigger" @click="toggleMenu">
            <span>{{ authStore.user?.username || '用户' }}</span>
            <span class="caret">▾</span>
          </button>
          <div v-if="menuOpen" class="dropdown-menu">
            <RouterLink to="/settings" @click="closeMenu">设置</RouterLink>
            <button class="dropdown-item" @click="handleLogout">退出登录</button>
          </div>
        </div>
      </div>
    </header>

    <main class="layout-main">
      <router-view />
    </main>
  </div>
</template>

<style scoped>
.layout {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

.layout-header {
  background: #fff;
  border-bottom: 1px solid #e5e6eb;
  position: sticky;
  top: 0;
  z-index: 100;
}

.header-inner {
  max-width: 1200px;
  margin: 0 auto;
  padding: 0 24px;
  height: 56px;
  display: flex;
  align-items: center;
  gap: 32px;
}

.logo {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 20px;
  font-weight: 700;
  color: #1c1f23;
}

.logo__img {
  width: 28px;
  height: 28px;
  object-fit: contain;
  border-radius: 4px;
  flex-shrink: 0;
}

.logo__text {
  white-space: nowrap;
}

.nav-menu {
  display: flex;
  gap: 24px;
  flex: 1;
}

.nav-menu a {
  color: #4e5969;
  font-size: 14px;
  padding: 4px 0;
  border-bottom: 2px solid transparent;
  transition: color 0.2s;
}

.nav-menu a:hover {
  color: #1668dc;
}

.nav-menu a.active {
  color: #1668dc;
  border-bottom-color: #1668dc;
}

.user-dropdown {
  position: relative;
}

.user-trigger {
  display: flex;
  align-items: center;
  gap: 4px;
  background: none;
  border: 1px solid #e5e6eb;
  border-radius: 6px;
  padding: 6px 12px;
  cursor: pointer;
  font-size: 14px;
  color: #4e5969;
}

.user-trigger:hover {
  border-color: #1668dc;
  color: #1668dc;
}

.caret {
  font-size: 10px;
}

.dropdown-menu {
  position: absolute;
  right: 0;
  top: calc(100% + 4px);
  min-width: 160px;
  background: #fff;
  border: 1px solid #e5e6eb;
  border-radius: 6px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.dropdown-menu a,
.dropdown-item {
  display: block;
  padding: 10px 16px;
  font-size: 14px;
  color: #4e5969;
  background: none;
  border: none;
  text-align: left;
  cursor: pointer;
  width: 100%;
}

.dropdown-menu a:hover,
.dropdown-item:hover {
  background: #f2f3f5;
  color: #1668dc;
}

.layout-main {
  flex: 1;
  max-width: 1200px;
  width: 100%;
  margin: 0 auto;
  padding: 24px;
}
</style>
