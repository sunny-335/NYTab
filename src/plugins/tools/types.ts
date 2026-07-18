import type { ToolPlugin } from '@/types/plugin'

/**
 * 工具插件定义辅助函数（类似 Vite 的 defineConfig）。
 * 运行时直接返回入参，仅用于在 IDE 中获得 ToolPlugin 的类型提示与校验。
 */
export function defineToolPlugin(plugin: ToolPlugin): ToolPlugin {
  return plugin
}

export type { ToolPlugin, ToolPluginMeta, ToolCategory } from '@/types/plugin'
