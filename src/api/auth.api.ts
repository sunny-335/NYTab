import { http } from './request'

/** Public projection of a user row (no password_hash / lock fields). */
export interface AuthUser {
  id: number
  username: string
  email?: string | null
  display_name?: string | null
  avatar_url?: string | null
  preferences?: Record<string, unknown>
}

/** POST /auth/login response data. */
export interface LoginResult {
  access_token: string
  refresh_token: string
  expires_in: number
  user: AuthUser
}

/** POST /auth/refresh response data (note: no new refresh_token is issued). */
export interface RefreshResult {
  access_token: string
  expires_in: number
}

export const authApi = {
  login: (username: string, password: string) =>
    http.post<LoginResult>('/auth/login', { username, password }),

  refresh: (refreshToken: string) =>
    http.post<RefreshResult>('/auth/refresh', { refresh_token: refreshToken }),

  logout: (refreshToken: string) =>
    http.post<null>('/auth/logout', { refresh_token: refreshToken }),

  me: () => http.get<AuthUser>('/auth/me'),
}
