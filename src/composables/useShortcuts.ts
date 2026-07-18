import { onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import type { Router } from 'vue-router'
import {
  useShortcutsStore,
  normalizeComboString,
  type Shortcut,
  type ShortcutAction,
} from '@/stores/shortcuts.store'
import { useSearchStore } from '@/stores/search.store'

/* -------------------------------------------------------------------------- */
/* Constants                                                                   */
/* -------------------------------------------------------------------------- */

/** 序列快捷键的超时窗口(ms)——500ms 内按下下一键视为同一序列。 */
const SEQUENCE_TIMEOUT = 500

/** 序列缓冲区最大长度,防止无意义的超长序列。 */
const SEQUENCE_BUFFER_MAX = 5

const MODIFIER_KEYS = new Set(['control', 'meta', 'alt', 'shift'])

/* -------------------------------------------------------------------------- */
/* Input-focus guard                                                           */
/* -------------------------------------------------------------------------- */

/** 当前聚焦元素是否为输入框(input / textarea / contenteditable)。 */
function isInputFocused(): boolean {
  const el = document.activeElement
  if (!el) return false
  const tag = el.tagName.toLowerCase()
  return (
    tag === 'input' ||
    tag === 'textarea' ||
    (el as HTMLElement).isContentEditable
  )
}

/* -------------------------------------------------------------------------- */
/* Recording guard — 录制器激活时暂停全局快捷键                                */
/* -------------------------------------------------------------------------- */

let _recording = false

/** 录制器开启/关闭时调用,暂停或恢复全局快捷键监听。 */
export function setShortcutRecording(value: boolean): void {
  _recording = value
}

/* -------------------------------------------------------------------------- */
/* Event bus — focus_search / toggle_search_mode 等动作通过事件总线分发       */
/* -------------------------------------------------------------------------- */

export type ShortcutEventName = 'focus_search' | 'toggle_search_mode'

const eventListeners: Record<ShortcutEventName, Set<() => void>> = {
  focus_search: new Set(),
  toggle_search_mode: new Set(),
}

/** 订阅快捷键事件。返回取消订阅函数。 */
export function onShortcutEvent(
  name: ShortcutEventName,
  handler: () => void,
): () => void {
  eventListeners[name].add(handler)
  return () => {
    eventListeners[name].delete(handler)
  }
}

function emitShortcutEvent(name: ShortcutEventName): void {
  eventListeners[name].forEach((h) => h())
}

/* -------------------------------------------------------------------------- */
/* Key normalization                                                           */
/* -------------------------------------------------------------------------- */

/** 把 event.key 归一化为小写字符串,空格映射为 'space'。 */
function normalizeKey(key: string): string {
  const lower = key.toLowerCase()
  if (lower === ' ') return 'space'
  return lower
}

/** 从 KeyboardEvent 构建归一化的 combo 字符串(如 'ctrl+shift+k')。 */
function buildCombo(event: KeyboardEvent): string {
  const parts: string[] = []
  if (event.ctrlKey) parts.push('ctrl')
  if (event.metaKey) parts.push('win')
  if (event.altKey) parts.push('alt')
  if (event.shiftKey) parts.push('shift')
  const main = normalizeKey(event.key)
  if (!MODIFIER_KEYS.has(main)) {
    parts.push(main)
  }
  return normalizeComboString(parts.join('+'))
}

/* -------------------------------------------------------------------------- */
/* Action executor                                                             */
/* -------------------------------------------------------------------------- */

function executeAction(action: ShortcutAction, router: Router): void {
  switch (action.kind) {
    case 'focus_search': {
      // 优先聚焦页面上的搜索输入框;找不到则通过事件总线通知组件
      const input = document.querySelector<HTMLInputElement>(
        'input[type="search"], .search-bar input, .search-input input, .search-input, input[role="searchbox"]',
      )
      if (input) {
        input.focus()
      } else {
        emitShortcutEvent('focus_search')
      }
      break
    }
    case 'toggle_search_mode': {
      // 站内搜索模式切换:bookmarks ↔ mixed
      const searchStore = useSearchStore()
      const next = searchStore.searchMode === 'bookmarks' ? 'mixed' : 'bookmarks'
      searchStore.setSearchMode(next)
      emitShortcutEvent('toggle_search_mode')
      break
    }
    case 'open_url':
      if (action.url) {
        window.open(action.url, '_blank')
      }
      break
    case 'go_path':
      if (action.path) {
        void router.push(action.path)
      }
      break
  }
}

/* -------------------------------------------------------------------------- */
/* useShortcuts composable                                                     */
/* -------------------------------------------------------------------------- */

/**
 * 全局快捷键监听 composable。
 *
 * 在组件 setup 中调用一次即可注册 window keydown 监听器:
 * - 序列键:缓冲区记录连续按键,500ms 内匹配则触发
 * - 组合键:检测修饰键 + 主键,归一化后匹配
 * - 输入框聚焦时自动禁用
 * - 录制器激活时暂停(通过 setShortcutRecording)
 *
 * onUnmounted 时自动清理监听器和定时器。
 */
export function useShortcuts(): void {
  const store = useShortcutsStore()
  const router = useRouter()

  let buffer: string[] = []
  let timer: ReturnType<typeof setTimeout> | null = null

  function clearBuffer(): void {
    buffer = []
    if (timer) {
      clearTimeout(timer)
      timer = null
    }
  }

  /** 在缓冲区尾部查找匹配的序列快捷键(优先长序列)。 */
  function matchSequence(all: Shortcut[]): Shortcut | null {
    const sequences = all
      .filter((s) => s.type === 'sequence')
      .sort((a, b) => b.keys.length - a.keys.length)
    for (const s of sequences) {
      if (s.keys.length > buffer.length) continue
      const offset = buffer.length - s.keys.length
      let ok = true
      for (let i = 0; i < s.keys.length; i++) {
        if (buffer[offset + i] !== s.keys[i].toLowerCase()) {
          ok = false
          break
        }
      }
      if (ok) return s
    }
    return null
  }

  /** 在快捷键列表中查找匹配的组合键。 */
  function matchCombo(all: Shortcut[], combo: string): Shortcut | null {
    return (
      all.find((s) => {
        if (s.type !== 'combo') return false
        return normalizeComboString(s.keys.join('+')) === combo
      }) ?? null
    )
  }

  function onKeyDown(event: KeyboardEvent): void {
    // 录制器激活时暂停全局快捷键
    if (_recording) return
    // 输入框聚焦时不触发
    if (isInputFocused()) return

    const all = store.shortcuts
    if (all.length === 0) return

    const key = normalizeKey(event.key)

    // 纯修饰键按下不处理(等主键)
    if (MODIFIER_KEYS.has(key)) return

    // 组合键:任意修饰键按下时走 combo 路径
    if (event.ctrlKey || event.metaKey || event.altKey) {
      const combo = buildCombo(event)
      const matched = matchCombo(all, combo)
      if (matched) {
        event.preventDefault()
        executeAction(matched.action, router)
      }
      // 修饰键按下时清空序列缓冲区,避免混用
      clearBuffer()
      return
    }

    // 序列键:追加到缓冲区
    buffer.push(key)
    if (timer) clearTimeout(timer)
    timer = setTimeout(clearBuffer, SEQUENCE_TIMEOUT)

    const matched = matchSequence(all)
    if (matched) {
      event.preventDefault()
      executeAction(matched.action, router)
      clearBuffer()
      return
    }

    // 缓冲区过长时裁剪(保留尾部,可能匹配长序列的前缀)
    if (buffer.length > SEQUENCE_BUFFER_MAX) {
      buffer = buffer.slice(-SEQUENCE_BUFFER_MAX)
    }
  }

  onMounted(() => {
    window.addEventListener('keydown', onKeyDown)
  })

  onUnmounted(() => {
    window.removeEventListener('keydown', onKeyDown)
    clearBuffer()
  })
}
