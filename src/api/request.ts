import axios from 'axios'
import type {
  AxiosInstance,
  AxiosRequestConfig,
  AxiosError,
  InternalAxiosRequestConfig,
} from 'axios'
import { Message } from 'vue-devui'
import router from '@/router'

/** localStorage keys for persisted auth state. */
export const ACCESS_TOKEN_KEY = 'nytab_access_token'
export const REFRESH_TOKEN_KEY = 'nytab_refresh_token'
export const USER_KEY = 'nytab_user'

/** Unified backend response envelope: { code, message, data }. */
export interface ApiEnvelope<T = unknown> {
  code: number
  message: string
  data: T
}

const request: AxiosInstance = axios.create({
  baseURL: '/api',
  timeout: 15000,
})

/* -------------------------------------------------------------------------- */
/* Request interceptor — attach JWT Bearer token from localStorage            */
/* -------------------------------------------------------------------------- */
request.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = localStorage.getItem(ACCESS_TOKEN_KEY)
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

/* -------------------------------------------------------------------------- */
/* Token refresh queue — prevent parallel refresh + retry queued requests     */
/* -------------------------------------------------------------------------- */
let _isRefreshing = false
let _queue: Array<{
  resolve: (token: string) => void
  reject: (reason?: unknown) => void
}> = []

function clearAuthAndRedirect() {
  localStorage.removeItem(ACCESS_TOKEN_KEY)
  localStorage.removeItem(REFRESH_TOKEN_KEY)
  localStorage.removeItem(USER_KEY)
  if (router.currentRoute.value.name !== 'login') {
    router.push({ name: 'login' })
  }
}

/**
 * Exchange the stored refresh token for a new access token.
 * Uses a bare axios call (bypassing interceptors) to avoid recursion.
 * Returns the new access token, or null on failure.
 */
async function refreshAccessToken(): Promise<string | null> {
  const refreshToken = localStorage.getItem(REFRESH_TOKEN_KEY)
  if (!refreshToken) return null
  try {
    const res = await axios.post<
      ApiEnvelope<{ access_token: string; expires_in: number }>
    >('/api/auth/refresh', { refresh_token: refreshToken })
    const newToken = res.data?.data?.access_token
    if (!newToken) return null
    localStorage.setItem(ACCESS_TOKEN_KEY, newToken)
    // Sync the new token into the auth store (dynamic import breaks the
    // request → router → store → api → request circular dependency).
    const { useAuthStore } = await import('@/stores/auth.store')
    useAuthStore().setAccessToken(newToken)
    return newToken
  } catch {
    return null
  }
}

/* -------------------------------------------------------------------------- */
/* Response interceptor — unwrap envelope + centralised error handling       */
/* -------------------------------------------------------------------------- */
request.interceptors.response.use(
  // Success: unwrap { code, message, data } → return data directly.
  (response) => {
    const envelope = response.data as ApiEnvelope | undefined
    if (envelope && typeof envelope.code === 'number') {
      if (envelope.code === 0) {
        // The interceptor returns the unwrapped payload, not a full
        // AxiosResponse. Api wrappers cast to concrete types via `http`.
        return envelope.data as unknown as typeof response
      }
      // Non-zero code inside a 2xx response (shouldn't happen with the
      // current backend, but handle defensively).
      Message.error(envelope.message || '请求失败')
      return Promise.reject(new Error(envelope.message))
    }
    return response.data as unknown as typeof response
  },

  // Error: classify by HTTP status + business code.
  async (error: AxiosError<ApiEnvelope>) => {
    const status = error.response?.status
    const body = error.response?.data
    const code = body?.code
    const message = body?.message || error.message || '网络错误'
    const originalConfig = error.config as
      | (InternalAxiosRequestConfig & { _retried?: boolean })
      | undefined
    const url = originalConfig?.url || ''
    const isAuthEndpoint =
      url.includes('/auth/login') || url.includes('/auth/refresh')

    // 401 on a protected endpoint (token expired) → attempt silent refresh.
    if (
      status === 401 &&
      code === 40101 &&
      !isAuthEndpoint &&
      originalConfig &&
      !originalConfig._retried
    ) {
      if (_isRefreshing) {
        // Another refresh is in flight; queue this request until it resolves.
        return new Promise<string>((resolve, reject) => {
          _queue.push({ resolve, reject })
        }).then((token) => {
          originalConfig.headers.Authorization = `Bearer ${token}`
          originalConfig._retried = true
          return request(originalConfig)
        })
      }

      _isRefreshing = true
      try {
        const newToken = await refreshAccessToken()
        if (!newToken) {
          _queue.forEach((item) => item.reject(error))
          _queue = []
          clearAuthAndRedirect()
          return Promise.reject(error)
        }
        _queue.forEach((item) => item.resolve(newToken))
        _queue = []
        originalConfig.headers.Authorization = `Bearer ${newToken}`
        originalConfig._retried = true
        return request(originalConfig)
      } catch {
        _queue.forEach((item) => item.reject(error))
        _queue = []
        clearAuthAndRedirect()
        return Promise.reject(error)
      } finally {
        _isRefreshing = false
      }
    }

    // 503 — system not installed → redirect to setup wizard.
    if (status === 503 && code === 50301) {
      if (router.currentRoute.value.name !== 'setup') {
        router.push({ name: 'setup' })
      }
      return Promise.reject(error)
    }

    // 409 — system already installed (e.g. revisiting /setup).
    if (status === 409 && code === 40901) {
      if (router.currentRoute.value.name === 'setup') {
        router.push({ name: 'login' })
      } else {
        Message.error(message)
      }
      return Promise.reject(error)
    }

    // 429 — brute-force lockout.
    if (status === 429 && code === 42901) {
      Message.error('尝试过多，请 15 分钟后再试')
      return Promise.reject(error)
    }

    // All other errors (including 401/40102 wrong login credentials, 422
    // validation, 500, etc.) → surface the message via toast.
    Message.error(message)
    return Promise.reject(error)
  },
)

/**
 * Typed HTTP helpers. Because the response interceptor unwraps the envelope,
 * these return the data payload directly (not an AxiosResponse).
 */
export const http = {
  get: <T = unknown>(url: string, config?: AxiosRequestConfig) =>
    request.get(url, config) as unknown as Promise<T>,
  post: <T = unknown>(url: string, data?: unknown, config?: AxiosRequestConfig) =>
    request.post(url, data, config) as unknown as Promise<T>,
  put: <T = unknown>(url: string, data?: unknown, config?: AxiosRequestConfig) =>
    request.put(url, data, config) as unknown as Promise<T>,
  delete: <T = unknown>(url: string, config?: AxiosRequestConfig) =>
    request.delete(url, config) as unknown as Promise<T>,
}

export default request
