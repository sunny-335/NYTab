<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'

/**
 * Settings — 主设置页(左侧 Tab 导航 + 右侧 router-view)。
 *
 * 8 个子页通过嵌套路由渲染在右侧内容区,左侧高亮当前 Tab。
 * 不使用 d-tabs/d-menu:嵌套路由用 RouterLink + active-class 最直观。
 */
interface TabItem {
  key: string
  label: string
  to: string
}

const tabs: TabItem[] = [
  { key: 'profile', label: '个人中心', to: '/settings/profile' },
  { key: 'branding', label: '个性化', to: '/settings/branding' },
  { key: 'search', label: '搜索引擎', to: '/settings/search' },
  { key: 'shortcuts', label: '快捷键', to: '/settings/shortcuts' },
  { key: 'background', label: '背景', to: '/settings/background' },
  { key: 'weather', label: '天气', to: '/settings/weather' },
  { key: 'dev-mode', label: '开发者选项', to: '/settings/dev-mode' },
  { key: 'about', label: '关于', to: '/settings/about' },
]

const route = useRoute()

/** 取 /settings/<key> 的第二段作为高亮依据。 */
const activeKey = computed(() => {
  const segs = route.path.split('/')
  return segs[2] || 'profile'
})
</script>

<template>
  <div class="settings-page">
    <aside class="settings-nav">
      <h1 class="settings-nav__title">设置</h1>
      <nav class="settings-nav__list">
        <RouterLink
          v-for="tab in tabs"
          :key="tab.key"
          :to="tab.to"
          class="settings-nav__item"
          :class="{ 'is-active': activeKey === tab.key }"
        >
          {{ tab.label }}
        </RouterLink>
      </nav>
    </aside>

    <section class="settings-content">
      <router-view />
    </section>
  </div>
</template>

<style scoped>
.settings-page {
  display: flex;
  gap: 24px;
  align-items: flex-start;
}

.settings-nav {
  width: 200px;
  flex-shrink: 0;
  position: sticky;
  top: 80px;
  background: #fff;
  border: 1px solid #e5e6eb;
  border-radius: 8px;
  padding: 16px 0;
}

.settings-nav__title {
  margin: 0 0 12px;
  padding: 0 20px;
  font-size: 18px;
  font-weight: 600;
  color: #1c1f23;
}

.settings-nav__list {
  display: flex;
  flex-direction: column;
}

.settings-nav__item {
  display: block;
  padding: 10px 20px;
  font-size: 14px;
  color: #4e5969;
  text-decoration: none;
  border-left: 3px solid transparent;
  transition: background 0.15s, color 0.15s;
}

.settings-nav__item:hover {
  background: #f7f8fa;
  color: #1668dc;
}

.settings-nav__item.is-active {
  background: #e8f3ff;
  color: #1668dc;
  font-weight: 600;
  border-left-color: #1668dc;
}

.settings-content {
  flex: 1;
  min-width: 0;
}

/* 移动端:nav 横向滚动 */
@media (max-width: 768px) {
  .settings-page {
    flex-direction: column;
    gap: 16px;
  }

  .settings-nav {
    width: 100%;
    position: static;
  }

  .settings-nav__list {
    flex-direction: row;
    overflow-x: auto;
    padding: 0 8px;
  }

  .settings-nav__item {
    white-space: nowrap;
    border-left: none;
    border-bottom: 3px solid transparent;
    padding: 8px 14px;
  }

  .settings-nav__item.is-active {
    border-left: none;
    border-bottom-color: #1668dc;
  }
}
</style>
