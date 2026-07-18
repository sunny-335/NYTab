import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { debounce } from 'lodash-es'
import { workspaceApi } from '@/api/workspace.api'
import { toolApi } from '@/api/tool.api'
import type { LayoutItem, WorkspaceSettings } from '@/types/workspace'
import type { ToolPluginMeta } from '@/types/plugin'

/**
 * Workspace store — manages the modular dashboard state.
 *
 * Holds three pieces of state sourced from the backend:
 *  - `layout`: per-user tool card positions/sizes (workspace_layouts.layout)
 *  - `settings`: grid cols / rowHeight / gap (workspace_layouts.settings)
 *  - `availablePlugins`: tool registry list (GET /tools/registry)
 *
 * Layout mutations (toggle / remove / update / reset) modify local state
 * immediately and trigger a debounced (500ms) `PUT /workspace/layout`.
 */
export const useWorkspaceStore = defineStore('workspace', () => {
  /* ----------------------------- State ----------------------------- */
  const layout = ref<LayoutItem[]>([])
  const settings = ref<WorkspaceSettings>({
    cols: 12,
    rowHeight: 80,
    gap: 16,
  })
  const availablePlugins = ref<ToolPluginMeta[]>([])
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<Error | null>(null)

  /* ----------------------------- Getters ----------------------------- */
  /** Layout items currently visible (enabled !== false). */
  const enabledItems = computed(() =>
    layout.value.filter((i) => i.enabled !== false),
  )

  /** Plugin ids available in the registry but not yet added to the layout. */
  const disabledPluginIds = computed(() => {
    const inLayout = new Set(layout.value.map((i) => i.pluginId))
    return availablePlugins.value
      .map((p) => p.pluginId)
      .filter((id) => !inLayout.has(id))
  })

  /* ----------------------------- Actions ----------------------------- */
  /**
   * Parallel-fetch layout, settings, and the tool registry on mount.
   * The /workspace/layout endpoint also returns settings, but we prefer
   * the dedicated /workspace/settings response as the canonical source.
   */
  async function init() {
    loading.value = true
    error.value = null
    try {
      const [layoutRes, settingsRes, registryRes] = await Promise.all([
        workspaceApi.getLayout(),
        workspaceApi.getSettings(),
        toolApi.registry(),
      ])
      layout.value = layoutRes.layout ?? []
      settings.value = { ...settings.value, ...settingsRes.settings }
      // ToolRegistryEntry is structurally compatible with ToolPluginMeta
      // (all required fields match; extra optionals are simply absent).
      availablePlugins.value = registryRes.tools as ToolPluginMeta[]
    } catch (e) {
      error.value = e as Error
    } finally {
      loading.value = false
    }
  }

  /** Internal debounced (500ms) layout persistence. */
  const _debouncedSaveLayout = debounce(async () => {
    saving.value = true
    error.value = null
    try {
      await workspaceApi.saveLayout(layout.value)
    } catch (e) {
      error.value = e as Error
    } finally {
      saving.value = false
    }
  }, 500)

  /** Trigger debounced layout save. Safe to call on every mutation. */
  function saveLayout() {
    _debouncedSaveLayout()
  }

  /** Persist settings immediately (no debounce). */
  async function saveSettings() {
    saving.value = true
    error.value = null
    try {
      await workspaceApi.saveSettings(settings.value)
    } catch (e) {
      error.value = e as Error
    } finally {
      saving.value = false
    }
  }

  /**
   * Toggle a tool's enabled flag. If the plugin is not yet in the layout,
   * add it with a default 4×3 footprint at the origin.
   */
  function togglePlugin(pluginId: string, enabled: boolean) {
    const existing = layout.value.find((i) => i.pluginId === pluginId)
    if (existing) {
      existing.enabled = enabled
    } else {
      layout.value.push({
        pluginId,
        enabled,
        x: 0,
        y: 0,
        w: 4,
        h: 3,
      })
    }
    saveLayout()
  }

  /** Remove a tool card from the layout entirely. */
  function removePlugin(pluginId: string) {
    const idx = layout.value.findIndex((i) => i.pluginId === pluginId)
    if (idx >= 0) {
      layout.value.splice(idx, 1)
    }
    saveLayout()
  }

  /** Patch a single layout item (used after drag / resize). */
  function updateLayoutItem(pluginId: string, partial: Partial<LayoutItem>) {
    const item = layout.value.find((i) => i.pluginId === pluginId)
    if (item) {
      Object.assign(item, partial)
    }
    saveLayout()
  }

  /** Clear all layout items (settings are preserved). */
  function resetLayout() {
    layout.value = []
    saveLayout()
  }

  return {
    // state
    layout,
    settings,
    availablePlugins,
    loading,
    saving,
    error,
    // getters
    enabledItems,
    disabledPluginIds,
    // actions
    init,
    saveLayout,
    saveSettings,
    togglePlugin,
    removePlugin,
    updateLayoutItem,
    resetLayout,
  }
})
