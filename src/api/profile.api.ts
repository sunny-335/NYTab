import { http } from './request'
import type { AuthUser } from './auth.api'

/** PUT /profile request body (only username is required by this task). */
export interface ProfileUpdateBody {
  username: string
  email?: string
}

/**
 * PUT /profile/password request body.
 * The backend expects `current_password` (not `old_password`).
 */
export interface ChangePasswordBody {
  current_password: string
  new_password: string
}

export const profileApi = {
  update: (username: string) =>
    http.put<AuthUser>('/profile', { username } satisfies ProfileUpdateBody),

  changePassword: (currentPassword: string, newPassword: string) =>
    http.put<null>(
      '/profile/password',
      {
        current_password: currentPassword,
        new_password: newPassword,
      } satisfies ChangePasswordBody,
    ),
}
