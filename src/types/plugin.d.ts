import type { Component } from 'vue'

export type ToolCategory = 'efficiency' | 'developer' | 'lifestyle'

export interface ToolPluginMeta {
  /** 唯一 ID，匹配 ^[a-zA-Z0-9_-]+$，长度 1-64 */
  pluginId: string
  /** 显示名称 */
  name: string
  /** 分类 */
  category: ToolCategory
  /** 图标（icon name 或 URL） */
  icon?: string
  /** 描述 */
  description?: string
  /** 默认布局尺寸（栅格单元） */
  defaultSize?: { w: number; h: number }
  /** 最小尺寸 */
  minSize?: { w: number; h: number }
  /** 是否允许在工作台关闭（默认 true） */
  dismissible?: boolean
}

export interface ToolPlugin {
  meta: ToolPluginMeta
  /** Vue 3 异步组件定义（用于 <component :is>） */
  component: () => Promise<{ default: Component }>
  /** 可选：初始化时返回的默认 state（首次使用、后端无 state 时使用） */
  defaultState?: () => Record<string, unknown>
}
