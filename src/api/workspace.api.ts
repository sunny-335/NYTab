import { http } from './request'
import type { LayoutItem, WorkspaceSettings } from '@/types/workspace'

/**
 * GET /workspace/layout 响应数据。
 *
 * 注意：后端 WorkspaceRepository::getLayout() 同时返回 layout 与 settings，
 * 即每用户一行的 workspace_layouts 表中两个 JSONB 字段一并读出。
 * 响应经 request.ts 的拦截器解包 envelope 后即为该结构。
 */
export interface WorkspaceLayoutResponse {
  layout: LayoutItem[]
  settings: WorkspaceSettings
}

/** GET /workspace/settings 响应数据。 */
export interface WorkspaceSettingsResponse {
  settings: WorkspaceSettings
}

export const workspaceApi = {
  /** 拉取当前用户的工作台布局（同时返回 settings）。 */
  getLayout: () => http.get<WorkspaceLayoutResponse>('/workspace/layout'),

  /** 更新布局（整体覆盖）。 */
  saveLayout: (layout: LayoutItem[]) =>
    http.put<null>('/workspace/layout', { layout }),

  /** 拉取工作台全局设置。 */
  getSettings: () => http.get<WorkspaceSettingsResponse>('/workspace/settings'),

  /** 更新工作台全局设置（合并写入）。 */
  saveSettings: (settings: WorkspaceSettings) =>
    http.put<null>('/workspace/settings', { settings }),
}
