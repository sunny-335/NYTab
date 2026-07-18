import { http } from './request'

/**
 * Branding payload returned by the backend.
 *
 * The `copyright` field is hardcoded server-side (see BrandingService::COPYRIGHT)
 * and can never be modified by any API; the backend silently ignores any
 * client-supplied `copyright` value on PUT /branding.
 */
export interface Branding {
  nickname: string
  title: string
  logo: string
  copyright: string
}

/** PUT /branding body. `copyright` is intentionally absent — read-only. */
export interface BrandingUpdatePayload {
  nickname?: string
  title?: string
  logo?: string
}

export const brandingApi = {
  /** 公开读取(登录页/安装向导/About 页均可调)。 */
  get: () => http.get<Branding>('/branding'),

  /** 鉴权更新 nickname / title / logo(copyright 不可改)。 */
  update: (payload: BrandingUpdatePayload) =>
    http.put<Branding>('/branding', payload),

  /** 鉴权 multipart 上传 logo(PNG/JPG/SVG, ≤500KB)。 */
  uploadLogo: (file: File) => {
    const formData = new FormData()
    formData.append('logo', file)
    return http.post<Branding>('/branding/logo', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
  },
}
