import type { Component } from 'vue'
import type { ToolPlugin, ToolCategory } from '@/types/plugin'

/**
 * 使用 Vite 的 import.meta.glob 一次性收集所有插件模块。
 * eager: false（默认）→ 每个插件成为独立的异步 chunk，按需加载。
 * 即使目前没有实际插件文件，glob 也会返回空对象，不会报错。
 */
const pluginModules = import.meta.glob<Record<string, unknown>>('./*/index.ts')

/** 静态注册表：pluginId → ToolPlugin（加载后缓存）。 */
const registry = new Map<string, ToolPlugin>()

/** 从路径 ./<pluginId>/index.ts 中提取 pluginId。 */
function extractPluginId(path: string): string | null {
  const match = path.match(/^\.\/(.+?)\/index\.ts$/)
  return match ? match[1] : null
}

/** 异步加载并缓存插件模块。 */
async function loadPlugin(pluginId: string): Promise<ToolPlugin | null> {
  const cached = registry.get(pluginId)
  if (cached) return cached

  const path = `./${pluginId}/index.ts`
  const loader = pluginModules[path]
  if (!loader) return null

  const mod = await loader()
  const plugin = (mod as { default?: ToolPlugin }).default
  if (!plugin || !plugin.meta || !plugin.component) {
    console.warn(
      `[NYTab] Plugin "${pluginId}" missing default export or meta/component`,
    )
    return null
  }

  registry.set(pluginId, plugin)
  return plugin
}

/** 按 pluginId 获取插件（异步加载 + 缓存）。 */
export async function getToolPlugin(
  pluginId: string,
): Promise<ToolPlugin | null> {
  return loadPlugin(pluginId)
}

/** 列出所有被 glob 扫描到的 pluginId（不触发模块加载）。 */
export function listPluginIds(): string[] {
  return Object.keys(pluginModules)
    .map(extractPluginId)
    .filter((id): id is string => id !== null)
}

/**
 * 按分类分组返回 pluginId。
 * 注意：此函数返回与后端 registry 一致的静态回退映射，
 * 实际使用时调用方应优先用 toolApi.registry() 拉取后端清单。
 */
export function listPluginsByCategory(
  _ids: string[],
): Record<ToolCategory, string[]> {
  return {
    efficiency: ['pomodoro', 'markdown', 'notes', 'clock'],
    developer: ['code-format', 'json-xml', 'base64', 'regex', 'color-picker'],
    lifestyle: ['exchange', 'unit-convert', 'password-gen', 'qrcode', 'weather'],
  }
}

/** 并行加载所有已扫描到的插件。 */
export async function loadAllPlugins(): Promise<ToolPlugin[]> {
  const ids = listPluginIds()
  const plugins = await Promise.all(ids.map(loadPlugin))
  return plugins.filter((p): p is ToolPlugin => p !== null)
}

/**
 * 解析 pluginId → 异步组件加载器（用于 <component :is="...">）。
 * 返回的函数签名为 () => Promise<{ default: Component }>，
 * 与 Vue 的 defineAsyncComponent 期望兼容。
 */
export function resolveComponent(
  pluginId: string,
): (() => Promise<{ default: Component }>) | null {
  const path = `./${pluginId}/index.ts`
  if (!pluginModules[path]) return null
  return async () => {
    const plugin = await loadPlugin(pluginId)
    if (!plugin) throw new Error(`Plugin not found: ${pluginId}`)
    return plugin.component()
  }
}
