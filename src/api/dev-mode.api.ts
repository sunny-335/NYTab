import { http } from './request'

/** GET /dev-mode/status 返回。 */
export interface DevModeStatus {
  enabled: boolean
}

/** POST /dev-mode/enable 返回。 */
export interface DevModeEnableResult {
  ok: boolean
  enabled: true
}

/** POST /dev-mode/disable 返回。 */
export interface DevModeDisableResult {
  ok: boolean
  enabled: false
}

export const devModeApi = {
  status: () => http.get<DevModeStatus>('/dev-mode/status'),
  enable: () => http.post<DevModeEnableResult>('/dev-mode/enable'),
  disable: () => http.post<DevModeDisableResult>('/dev-mode/disable'),
}
