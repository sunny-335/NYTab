import { createRouter, createWebHistory } from 'vue-router'
import type { RouteRecordRaw } from 'vue-router'
import { useSetupStore } from '@/stores/setup.store'
import { useAuthStore } from '@/stores/auth.store'
import { useBranding } from '@/composables/useBranding'
import { devModeApi } from '@/api/dev-mode.api'

/** Route-level metadata consumed by the global guard + App.vue layout switch. */
declare module 'vue-router' {
  interface RouteMeta {
    /** True when the route requires an authenticated user. */
    requiresAuth?: boolean
    /** Layout shell: 'default' (nav + content) or 'auth' (centered). */
    layout?: 'default' | 'auth'
    /** Guest-only page (login / setup) — authenticated users are bounced to /. */
    public?: boolean
  }
}

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'home',
    component: () => import('@/views/Home.vue'),
    meta: { requiresAuth: true, layout: 'default' },
  },
  {
    path: '/bookmarks',
    name: 'bookmarks',
    component: () => import('@/views/Bookmarks.vue'),
    meta: { requiresAuth: true, layout: 'default' },
  },
  {
    path: '/profile',
    redirect: '/settings/profile',
  },
  {
    path: '/change-password',
    redirect: '/settings/profile',
  },
  {
    path: '/settings',
    component: () => import('@/views/Settings.vue'),
    redirect: '/settings/profile',
    meta: { requiresAuth: true, layout: 'default' },
    children: [
      {
        path: 'profile',
        name: 'settings-profile',
        component: () => import('@/views/settings/ProfileSettings.vue'),
        meta: { requiresAuth: true, layout: 'default' },
      },
      {
        path: 'branding',
        name: 'settings-branding',
        component: () => import('@/views/settings/BrandingSettings.vue'),
        meta: { requiresAuth: true, layout: 'default' },
      },
      {
        path: 'search',
        name: 'settings-search',
        component: () => import('@/views/settings/SearchSettings.vue'),
        meta: { requiresAuth: true, layout: 'default' },
      },
      {
        path: 'shortcuts',
        name: 'settings-shortcuts',
        component: () => import('@/views/settings/ShortcutSettings.vue'),
        meta: { requiresAuth: true, layout: 'default' },
      },
      {
        path: 'background',
        name: 'settings-background',
        component: () => import('@/views/settings/BackgroundSettings.vue'),
        meta: { requiresAuth: true, layout: 'default' },
      },
      {
        path: 'weather',
        name: 'settings-weather',
        component: () => import('@/views/settings/WeatherSettings.vue'),
        meta: { requiresAuth: true, layout: 'default' },
      },
      {
        path: 'dev-mode',
        name: 'settings-dev-mode',
        component: () => import('@/views/settings/DevModeSettings.vue'),
        meta: { requiresAuth: true, layout: 'default' },
      },
      {
        path: 'about',
        name: 'settings-about',
        component: () => import('@/views/settings/AboutSettings.vue'),
        meta: { requiresAuth: true, layout: 'default' },
      },
    ],
  },
  {
    path: '/setup',
    name: 'setup',
    component: () => import('@/views/Setup.vue'),
    meta: { requiresAuth: false, public: true, layout: 'auth' },
  },
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/Login.vue'),
    meta: { requiresAuth: false, public: true, layout: 'auth' },
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/views/NotFound.vue'),
    meta: { requiresAuth: false, layout: 'default' },
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

/* -------------------------------------------------------------------------- */
/* Global navigation guard                                                    */
/* -------------------------------------------------------------------------- */
let _statusFetched = false
let _brandingLoaded = false
let _devModeFetched = false
let _devModeEnabled = false

router.beforeEach(async (to) => {
  const setupStore = useSetupStore()
  const authStore = useAuthStore()

  // 0. Preload branding (public, no auth needed).
  //    useBranding() exposes module-level refs + a one-shot load(); the
  //    inner watch syncs document.title as soon as the title ref updates.
  if (!_brandingLoaded) {
    _brandingLoaded = true
    void useBranding().load()
  }

  // 1. Fetch install status once on first navigation so the guard can
  //    decide between /setup and the normal auth flow.
  if (!_statusFetched) {
    _statusFetched = true
    await setupStore.fetchStatus()
  }

  // 2. Fetch dev-mode status once (requires auth).
  //    If unauthenticated, skip — the call would 401 and we fall back to
  //    the normal setup check. Subsequent navigations after login will
  //    retry (still gated by _devModeFetched).
  if (!_devModeFetched && authStore.isAuthenticated) {
    _devModeFetched = true
    try {
      const status = await devModeApi.status()
      _devModeEnabled = status.enabled
    } catch {
      // 401/403/500 — treat as "dev mode off"; interceptor already toasted.
      _devModeEnabled = false
    }
  }

  // 3. Dev mode enabled → skip the setup-installed check (don't force /setup).
  //    Otherwise: not installed → force every route to /setup (incl. /login).
  if (!_devModeEnabled && setupStore.installed === false) {
    if (to.name === 'setup') return true
    return { name: 'setup' }
  }

  // 4. System installed (or dev mode) + user revisits /setup → bounce to /login.
  if (to.name === 'setup') {
    return { name: 'login' }
  }

  // 5. Authenticated route + not logged in → /login?redirect=...
  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  // 6. Guest-only page (login) + already authenticated → /.
  if (to.meta.public && authStore.isAuthenticated) {
    return { path: '/' }
  }

  return true
})

export default router
