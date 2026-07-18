<script setup lang="ts">
import { computed } from 'vue'
import { usePluginState } from '@/composables/usePluginState'

interface MarkdownState {
  content: string
  [key: string]: unknown
}

const { state, patch } = usePluginState<MarkdownState>('markdown', {
  defaultState: () => ({ content: '' }),
})

const content = computed<string>(() => state.value?.content ?? '')

function onInput(e: Event): void {
  const val = (e.target as HTMLTextAreaElement).value
  patch({ content: val })
}

function clearAll(): void {
  patch({ content: '' })
}

const charCount = computed(() => content.value.length)
const wordCount = computed(() => {
  const trimmed = content.value.trim()
  if (!trimmed) return 0
  // 中英文混合：英文按空白分词，中文按字数累计
  const cjk = (trimmed.match(/[\u4e00-\u9fa5]/g) || []).length
  const nonCjk = trimmed
    .replace(/[\u4e00-\u9fa5]/g, ' ')
    .split(/\s+/)
    .filter(Boolean).length
  return cjk + nonCjk
})

/** HTML 转义，避免 XSS。 */
function escapeHtml(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

/**
 * 简易 Markdown 解析：基于正则替换，在转义 HTML 后应用规则。
 * 支持：代码块、行内代码、标题、加粗、斜体、链接、无序/有序列表、段落。
 */
function parseMarkdown(src: string): string {
  if (!src) return ''

  const lines = escapeHtml(src).split(/\r?\n/)
  const out: string[] = []
  let i = 0
  let inUl = false
  let inOl = false

  function closeLists(): void {
    if (inUl) {
      out.push('</ul>')
      inUl = false
    }
    if (inOl) {
      out.push('</ol>')
      inOl = false
    }
  }

  while (i < lines.length) {
    const line = lines[i]

    // 代码块 ```lang ... ```
    const fence = line.match(/^```(.*)$/)
    if (fence) {
      closeLists()
      const lang = fence[1]?.trim() || ''
      const buf: string[] = []
      i++
      while (i < lines.length && !/^```/.test(lines[i])) {
        buf.push(lines[i])
        i++
      }
      i++ // 跳过结束 ```
      out.push(
        `<pre><code class="lang-${escapeHtml(lang)}">${buf.join('\n')}</code></pre>`,
      )
      continue
    }

    // 标题 # ## ### #### ##### ######
    const header = line.match(/^(#{1,6})\s+(.*)$/)
    if (header) {
      closeLists()
      const level = header[1].length
      out.push(`<h${level}>${inline(header[2])}</h${level}>`)
      i++
      continue
    }

    // 无序列表 - / * / +
    const ul = line.match(/^[-*+]\s+(.*)$/)
    if (ul) {
      if (inOl) {
        out.push('</ol>')
        inOl = false
      }
      if (!inUl) {
        out.push('<ul>')
        inUl = true
      }
      out.push(`<li>${inline(ul[1])}</li>`)
      i++
      continue
    }

    // 有序列表 1. / 2.
    const ol = line.match(/^\d+\.\s+(.*)$/)
    if (ol) {
      if (inUl) {
        out.push('</ul>')
        inUl = false
      }
      if (!inOl) {
        out.push('<ol>')
        inOl = true
      }
      out.push(`<li>${inline(ol[1])}</li>`)
      i++
      continue
    }

    // 空行
    if (line.trim() === '') {
      closeLists()
      i++
      continue
    }

    // 段落
    closeLists()
    out.push(`<p>${inline(line)}</p>`)
    i++
  }
  closeLists()
  return out.join('\n')
}

/** 行内规则：行内代码、加粗、斜体、链接。注意先处理行内代码以免内部被解析。 */
function inline(s: string): string {
  // 行内代码 `code`
  const parts: string[] = []
  let rest = s
  const codeRe = /`([^`]+)`/
  let m: RegExpExecArray | null
  while ((m = codeRe.exec(rest)) !== null) {
    parts.push(applyInline(rest.slice(0, m.index)))
    parts.push(`<code>${m[1]}</code>`)
    rest = rest.slice(m.index + m[0].length)
  }
  parts.push(applyInline(rest))
  return parts.join('')
}

function applyInline(s: string): string {
  return s
    // 链接 [text](url)
    .replace(
      /\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,
      '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>',
    )
    // 加粗 **text**
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    // 斜体 *text*
    .replace(/\*([^*]+)\*/g, '<em>$1</em>')
}

const previewHtml = computed(() => parseMarkdown(content.value))
</script>

<template>
  <div v-if="state" class="md">
    <div class="md__toolbar">
      <span class="md__stats">{{ charCount }} 字符 · {{ wordCount }} 词</span>
      <d-button size="mini" type="common" @click="clearAll">清空</d-button>
    </div>
    <div class="md__body">
      <textarea
        class="md__editor"
        :value="content"
        placeholder="在此输入 Markdown..."
        spellcheck="false"
        @input="onInput"
      />
      <div class="md__preview" v-html="previewHtml"></div>
    </div>
  </div>
  <div v-else class="md md--loading">加载中…</div>
</template>

<style scoped>
.md {
  display: flex;
  flex-direction: column;
  height: 100%;
  gap: 8px;
}

.md--loading {
  color: #86909c;
  font-size: 13px;
  padding: 12px;
}

.md__toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 4px 4px 8px;
  border-bottom: 1px solid #f2f3f5;
}

.md__stats {
  font-size: 12px;
  color: #86909c;
}

.md__body {
  flex: 1;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  min-height: 0;
}

.md__editor {
  width: 100%;
  height: 100%;
  border: 1px solid #e5e6eb;
  border-radius: 6px;
  padding: 10px;
  font-family: 'JetBrains Mono', 'Fira Code', Consolas, monospace;
  font-size: 13px;
  line-height: 1.6;
  resize: none;
  outline: none;
  background: #fafbfc;
  color: #1c1f23;
}

.md__editor:focus {
  border-color: #1668dc;
  background: #fff;
}

.md__preview {
  border: 1px solid #e5e6eb;
  border-radius: 6px;
  padding: 10px 14px;
  overflow: auto;
  background: #fff;
  font-size: 13px;
  line-height: 1.6;
  color: #1c1f23;
}

.md__preview :deep(h1),
.md__preview :deep(h2),
.md__preview :deep(h3),
.md__preview :deep(h4),
.md__preview :deep(h5),
.md__preview :deep(h6) {
  margin: 8px 0 6px;
  font-weight: 600;
  line-height: 1.3;
}

.md__preview :deep(h1) { font-size: 20px; }
.md__preview :deep(h2) { font-size: 17px; }
.md__preview :deep(h3) { font-size: 15px; }
.md__preview :deep(h4),
.md__preview :deep(h5),
.md__preview :deep(h6) { font-size: 14px; }

.md__preview :deep(p) {
  margin: 6px 0;
}

.md__preview :deep(ul),
.md__preview :deep(ol) {
  margin: 6px 0;
  padding-left: 22px;
}

.md__preview :deep(li) {
  margin: 2px 0;
}

.md__preview :deep(code) {
  background: #f2f3f5;
  padding: 1px 5px;
  border-radius: 3px;
  font-family: Consolas, monospace;
  font-size: 12px;
  color: #d6337d;
}

.md__preview :deep(pre) {
  background: #1c1f23;
  color: #f7f8fa;
  padding: 10px 12px;
  border-radius: 6px;
  overflow: auto;
  margin: 8px 0;
}

.md__preview :deep(pre) code {
  background: transparent;
  color: inherit;
  padding: 0;
  font-size: 12px;
}

.md__preview :deep(a) {
  color: #1668dc;
  text-decoration: none;
}

.md__preview :deep(a):hover {
  text-decoration: underline;
}

.md__preview :deep(strong) {
  font-weight: 600;
}
</style>
