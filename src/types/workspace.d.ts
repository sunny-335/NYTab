export interface LayoutItem {
  pluginId: string
  /** 栅格 x 坐标 */
  x: number
  /** 栅格 y 坐标 */
  y: number
  /** 宽度（栅格单元） */
  w: number
  /** 高度（栅格单元） */
  h: number
  /** 是否启用显示 */
  enabled?: boolean
}

export interface WorkspaceLayout {
  items: LayoutItem[]
}

export interface WorkspaceSettings {
  /** 栅格列数 1-24 */
  cols: number
  /** 行高 px 40-300 */
  rowHeight: number
  /** 间距 px 0-40 */
  gap: number
  /** 主题标识（后端 validKeys 含 theme，默认 'default'） */
  theme?: string
}

export type Breakpoint = 'mobile' | 'tablet' | 'desktop'
