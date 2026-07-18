import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authApi } from '@/api/auth.api'
import type { AuthUser } from '@/api/auth.api'
import {
  ACCESS_TOKEN_KEY,
  REFRESH_TOKEN_KEY,
  USER_KEY,
} from '@/api/request'

/**
 * Authentication state.
 *
 * Tokens are persisted to localStorage so the axios interceptor (which runs
 * outside Vue's reactivity system) can read them. The store keeps its own
 * reactive copies for the `isAuthenticated` getter and UI consumption.
 */
export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(loadUser())
  const accessToken = ref<string>(localStorage.getItem(ACCESS_TOKEN_KEY) || '')
  const refreshToken = ref<string>(
    localStorage.getItem(REFRESH_TOKEN_KEY) || '',
  )

  const isAuthenticated = computed(
    () => !!accessToken.value && !!user.value,
  )

  function loadUser(): AuthUser | null {
    try {
      const raw = localStorage.getItem(USER_KEY)
      return raw ? (JSON.parse(raw) as AuthUser) : null
    } catch {
      return null
    }
  }

  function persistTokens(access: string, refresh: string) {
    localStorage.setItem(ACCESS_TOKEN_KEY, access)
    localStorage.setItem(REFRESH_TOKEN_KEY, refresh)
    accessToken.value = access
    refreshToken.value = refresh
  }

  function persistUser(u: AuthUser) {
    user.value = u
    localStorage.setItem(USER_KEY, JSON.stringify(u))
  }

  /** Called by request.ts after a silent token refresh. */
  function setAccessToken(token: string) {
    accessToken.value = token
    localStorage.setItem(ACCESS_TOKEN_KEY, token)
  }

  async function login(username: string, password: string) {
    const result = await authApi.login(username, password)
    persistTokens(result.access_token, result.refresh_token)
    persistUser(result.user)
    return result
  }

  /**
   * Refresh the access token. On failure the error is re-thrown so the
   * caller / interceptor can handle the redirect.
   */
  async function refresh() {
    const result = await authApi.refresh(refreshToken.value)
    setAccessToken(result.access_token)
    return result
  }

  async function logout() {
    try {
      if (refreshToken.value) {
        await authApi.logout(refreshToken.value)
      }
    } finally {
      // Clear regardless of whether the server call succeeded — the token
      // is discarded client-side either way.
      user.value = null
      accessToken.value = ''
      refreshToken.value = ''
      localStorage.removeItem(ACCESS_TOKEN_KEY)
      localStorage.removeItem(REFRESH_TOKEN_KEY)
      localStorage.removeItem(USER_KEY)
    }
  }

  async function fetchMe() {
    const u = await authApi.me()
    persistUser(u)
    return u
  }

  return {
    user,
    accessToken,
    refreshToken,
    isAuthenticated,
    login,
    refresh,
    logout,
    fetchMe,
    setAccessToken,
  }
})
