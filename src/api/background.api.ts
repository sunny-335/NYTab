import { http } from './request'

/** 背景类型:图片上传 / 外部 API 链接 / Bing 默认壁纸。 */
export type BackgroundType = 'image' | 'api' | 'bing'

/** GET /settings/background 返回的后端背景配置。 */
export interface BackgroundConfig {
  type: BackgroundType
  url: string
  lastUpdate: string | null
}

/** PUT /settings/background 请求体(部分字段,lastUpdate 后端忽略)。 */
export interface BackgroundUpdatePayload {
  type?: BackgroundType
  url?: string
}

/** POST /background/upload 返回结构。 */
export interface BackgroundUploadResult {
  url: string
}

export const backgroundApi = {
  /** 读取背景配置(public,首页加载即可用)。 */
  getBackground: () => http.get<BackgroundConfig>('/settings/background'),

  /** 更新背景配置(鉴权)。 */
  updateBackground: (payload: BackgroundUpdatePayload) =>
    http.put<BackgroundConfig>('/settings/background', payload),

  /** 上传背景图片(鉴权,multipart)。后端自动把背景设为 type=image。 */
  uploadBackground: (file: File) => {
    const formData = new FormData()
    formData.append('file', file)
    return http.post<BackgroundUploadResult>(
      '/background/upload',
      formData,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    )
  },
}
