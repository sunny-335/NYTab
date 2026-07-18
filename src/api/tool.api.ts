import { http } from './request'
import type { ToolCategory } from '@/types/plugin'

/** 后端 /tools/registry 返回的单条工具清单项。 */
export interface ToolRegistryEntry {
  pluginId: string
  name: string
  category: ToolCategory
  icon?: string
  description?: string
}

/** GET /tools/{pluginId}/state 响应（state 不存在时为 null）。 */
export interface ToolStateResponse {
  pluginId: string
  state: Record<string, unknown> | null
}

/** PUT / DELETE /tools/{pluginId}/state 成功响应。 */
export interface ToolStateOk {
  ok: boolean
}

export const toolApi = {
  /** 拉取后端硬编码的工具清单（实际返回 { tools: [...] }）。 */
  registry: () => http.get<{ tools: ToolRegistryEntry[] }>('/tools/registry'),

  /** 读取指定插件的状态。 */
  getState: (pluginId: string) =>
    http.get<ToolStateResponse>(`/tools/${pluginId}/state`),

  /** UPSERT 写入插件状态。 */
  saveState: (pluginId: string, state: Record<string, unknown>) =>
    http.put<ToolStateOk>(`/tools/${pluginId}/state`, { state }),

  /** 清空插件状态。 */
  deleteState: (pluginId: string) =>
    http.delete<ToolStateOk>(`/tools/${pluginId}/state`),
}
