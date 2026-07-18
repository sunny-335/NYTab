import { defineStore } from 'pinia'
import { ref } from 'vue'

/* -------------------------------------------------------------------------- */
/* Types                                                                       */
/* -------------------------------------------------------------------------- */

export type ShortcutAction =
  | { kind: 'focus_search' }
  | { kind: 'toggle_search_mode' }
  | { kind: 'open_url'; url: string }
  | { kind: 'go_path'; path: string }

export interface Shortcut {
  id: string
  /** ['s'] / ['z','s'] (sequence) 或 ['ctrl+k'] (combo) */
  keys: string[]
  type: 'sequence' | 'combo'
  action: ShortcutAction
  description: string
  /** 内置快捷键不可删除 */
  builtin?: boolean
}

/* -------------------------------------------------------------------------- */
/* Constants                                                                   */
/* -------------------------------------------------------------------------- */

const STORAGE_KEY = 'nytab_shortcuts'

/** 系统保留快捷键黑名单 —— 不允许用户绑定。 */
export const BLACKLIST: string[] = [
  'win+d', // 显示桌面
  'win+l', // 锁屏
  'ctrl+w', // 关闭标签
  'ctrl+shift+q', // 退出应用
  'alt+f4', // 关闭窗口
  'f5', // 刷新
  'ctrl+r', // 刷新
  'ctrl+t', // 新标签
  'ctrl+n', // 新窗口
  'ctrl+shift+n', // 隐身窗口
]

/** 内置快捷键 —— 不可删除,resetToDefault 恢复到此列表。 */
const BUILTIN_SHORTCUTS: Shortcut[] = [
  {
    id: 'builtin-focus-search',
    keys: ['s'],
    type: 'sequence',
    action: { kind: 'focus_search' },
    description: '聚焦搜索栏',
    builtin: true,
  },
  {
    id: 'builtin-toggle-search-mode',
    keys: ['z', 's'],
    type: 'sequence',
    action: { kind: 'toggle_search_mode' },
    description: '切换站内搜索模式',
    builtin: true,
  },
]

/* -------------------------------------------------------------------------- */
/* Helpers                                                                     */
/* -------------------------------------------------------------------------- */

/** 修饰键固定排序,保证 combo 字符串的一致性。 */
const MOD_ORDER = ['ctrl', 'win', 'alt', 'shift']
const MOD_ALIASES: Record<string, string> = {
  control: 'ctrl',
  meta: 'win',
}

/** 把单个 combo 字符串(如 'k+ctrl' / 'Shift+Q')归一化为 'ctrl+shift+q'。 */
export function normalizeComboString(combo: string): string {
  const parts = combo
    .split('+')
    .map((p) => p.trim().toLowerCase())
    .filter((p) => p.length > 0)
  const mods: string[] = []
  const others: string[] = []
  for (const p of parts) {
    const normalized = MOD_ALIASES[p] ?? p
    if (MOD_ORDER.includes(normalized)) {
      if (!mods.includes(normalized)) mods.push(normalized)
    } else {
      others.push(normalized)
    }
  }
  mods.sort((a, b) => MOD_ORDER.indexOf(a) - MOD_ORDER.indexOf(b))
  return [...mods, ...others].join('+')
}

/** 组合键冲突检测:排序后 join 比较(兼容 ['ctrl+k'] 和 ['k+ctrl'])。 */
export function comboKey(keys: string[]): string {
  return normalizeComboString(keys.join('+'))
}

/** 序列键冲突检测:keys 数组完全相同(顺序敏感)。 */
export function sequenceKey(keys: string[]): string {
  return keys.map((k) => k.toLowerCase()).join('+')
}

/** 判断两个快捷键是否冲突。 */
export function isConflict(a: Shortcut, b: Shortcut): boolean {
  if (a.type !== b.type) return false
  if (a.type === 'sequence') {
    return sequenceKey(a.keys) === sequenceKey(b.keys)
  }
  return comboKey(a.keys) === comboKey(b.keys)
}

/** 判断 keys 是否命中黑名单(仅对 combo 有意义)。 */
export function isBlacklisted(keys: string[], type: 'sequence' | 'combo'): boolean {
  if (type === 'sequence') {
    // 序列键也检查:单键的黑名单(如 f5)
    const normalized = sequenceKey(keys)
    return BLACKLIST.some((b) => {
      const parts = b.split('+')
      if (parts.length === 1) return parts[0] === normalized
      return false
    })
  }
  const normalized = comboKey(keys)
  return BLACKLIST.some((b) => comboKey([b]) === normalized)
}

/** 可视化按键序列: ['z','s'] → "Z + S",['ctrl+k'] → "Ctrl + K"。 */
export function formatKeys(keys: string[], type: 'sequence' | 'combo'): string {
  const LABELS: Record<string, string> = {
    ctrl: 'Ctrl',
    shift: 'Shift',
    alt: 'Alt',
    win: 'Win',
    meta: 'Win',
    enter: 'Enter',
    escape: 'Esc',
    space: 'Space',
    backspace: 'Backspace',
    tab: 'Tab',
    arrowup: '↑',
    arrowdown: '↓',
    arrowleft: '←',
    arrowright: '→',
  }
  const label = (k: string): string => LABELS[k] ?? (k.length === 1 ? k.toUpperCase() : k)
  if (type === 'sequence') {
    return keys.map((k) => label(k.toLowerCase())).join(' + ')
  }
  // combo: keys 形如 ['ctrl+k']
  return keys[0]
    .split('+')
    .map((p) => label(p.trim().toLowerCase()))
    .join(' + ')
}

/* -------------------------------------------------------------------------- */
/* Store                                                                       */
/* -------------------------------------------------------------------------- */

function loadFromStorage(): Shortcut[] {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return []
    const parsed = JSON.parse(raw) as Shortcut[]
    return Array.isArray(parsed) ? parsed : []
  } catch {
    return []
  }
}

function saveToStorage(shortcuts: Shortcut[]): void {
  try {
    // 只持久化用户自定义快捷键(内置快捷键在 init 时合并)
    const custom = shortcuts.filter((s) => !s.builtin)
    localStorage.setItem(STORAGE_KEY, JSON.stringify(custom))
  } catch {
    // ignore quota / serialization errors
  }
}

export const useShortcutsStore = defineStore('shortcuts', () => {
  const shortcuts = ref<Shortcut[]>([])

  function persist(): void {
    saveToStorage(shortcuts.value)
  }

  /** 加载内置 + 用户自定义(合并)。 */
  function init(): void {
    const userShortcuts = loadFromStorage()
    shortcuts.value = [...BUILTIN_SHORTCUTS, ...userShortcuts]
  }

  function addShortcut(shortcut: Shortcut): void {
    shortcuts.value.push(shortcut)
    persist()
  }

  function updateShortcut(id: string, patch: Partial<Omit<Shortcut, 'id'>>): void {
    const idx = shortcuts.value.findIndex((s) => s.id === id)
    if (idx >= 0) {
      shortcuts.value[idx] = { ...shortcuts.value[idx], ...patch }
      persist()
    }
  }

  function removeShortcut(id: string): void {
    const idx = shortcuts.value.findIndex((s) => s.id === id)
    if (idx >= 0 && !shortcuts.value[idx].builtin) {
      shortcuts.value.splice(idx, 1)
      persist()
    }
  }

  function resetToDefault(): void {
    shortcuts.value = [...BUILTIN_SHORTCUTS]
    persist()
  }

  /**
   * 查找与候选快捷键冲突的已有快捷键。
   * @param candidate  候选快捷键
   * @param excludeId  编辑时排除自身 id
   */
  function findConflict(
    candidate: Pick<Shortcut, 'keys' | 'type'>,
    excludeId?: string,
  ): Shortcut | undefined {
    return shortcuts.value.find(
      (s) => s.id !== excludeId && isConflict(s, candidate as Shortcut),
    )
  }

  return {
    shortcuts,
    init,
    addShortcut,
    updateShortcut,
    removeShortcut,
    resetToDefault,
    findConflict,
  }
})
