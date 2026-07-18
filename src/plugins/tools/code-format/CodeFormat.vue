<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { usePluginState } from '@/composables/usePluginState'

/**
 * CodeFormat — JS/CSS/HTML/JSON 简易格式化工具。
 *
 * 不引入 prettier 等大型库，按语言分别实现：
 * - JSON：JSON.stringify(JSON.parse(input), null, 2)
 * - HTML：基本缩进（标签前换行 + 缩进层级）
 * - CSS：`{` 后换行 + 缩进，`}` 减缩进
 * - JS：基于 `{` `;` `(` 等规则的极简缩进（不追求 AST 级别准确）
 *
 * 通过 usePluginState 持久化最近一次语言选择与输入内容。
 */
type Language = 'json' | 'html' | 'css' | 'js'

interface CodeFormatState {
  language: Language
  input: string
  [key: string]: unknown
}

const { state } = usePluginState<CodeFormatState>('code-format', {
  defaultState: () => ({ language: 'json', input: '' }),
})

const language = ref<Language>(state.value?.language ?? 'json')
const input = ref<string>(state.value?.input ?? '')
const output = ref<string>('')
const errorMsg = ref<string>('')

const languageOptions = [
  { name: 'JSON', value: 'json' },
  { name: 'HTML', value: 'html' },
  { name: 'CSS', value: 'css' },
  { name: 'JavaScript', value: 'js' },
]

const canFormat = computed(() => input.value.trim().length > 0)

watch(language, (val) => {
  if (state.value) state.value.language = val
})

watch(input, (val) => {
  if (state.value) state.value.input = val
})

function formatJson(code: string): string {
  return JSON.stringify(JSON.parse(code), null, 2)
}

function formatHtml(code: string): string {
  // 将标签前加换行，再按 `<` 计算缩进层级
  const tokens = code
    .replace(/>\s*</g, '><')
    .replace(/></g, '>\n<')
    .split('\n')
  let indent = 0
  const lines: string[] = []
  for (const raw of tokens) {
    const line = raw.trim()
    if (!line) continue
    // 自闭合标签不增加缩进
    const isSelfClose = /^<[^>]+\/>$/.test(line)
    // 闭合标签 </...> 减缩进
    const isClose = /^<\//.test(line)
    if (isClose) indent = Math.max(0, indent - 1)
    lines.push('  '.repeat(indent) + line)
    if (!isClose && !isSelfClose && /^<[^/!?][^>]*[^/]>$/.test(line)) {
      indent += 1
    }
  }
  return lines.join('\n')
}

function formatCss(code: string): string {
  // 在 `{` `}` `;` 后插入换行，并按层级缩进
  const normalized = code
    .replace(/\s*{\s*/g, ' {\n')
    .replace(/\s*;\s*/g, ';\n')
    .replace(/\s*}\s*/g, '\n}\n')
  const tokens = normalized.split('\n').map((l) => l.trim()).filter(Boolean)
  let indent = 0
  const lines: string[] = []
  for (const line of tokens) {
    if (line === '}') indent = Math.max(0, indent - 1)
    lines.push('  '.repeat(indent) + line)
    if (line.endsWith('{')) indent += 1
  }
  return lines.join('\n')
}

function formatJs(code: string): string {
  // 极简实现：按 `{` `}` `;` 切分并按层级缩进（不处理字符串字面量等边界）
  const tokens = code
    .replace(/;\s*/g, ';\n')
    .replace(/\{\s*/g, '{\n')
    .replace(/\}\s*/g, '\n}\n')
    .split('\n')
  let indent = 0
  const lines: string[] = []
  for (const raw of tokens) {
    const line = raw.trim()
    if (!line) continue
    if (line.startsWith('}')) indent = Math.max(0, indent - 1)
    lines.push('  '.repeat(indent) + line)
    if (line.endsWith('{') || (line.includes('{') && !line.includes('}'))) {
      indent += 1
    }
  }
  return lines.join('\n')
}

function doFormat(): void {
  errorMsg.value = ''
  if (!input.value.trim()) {
    output.value = ''
    return
  }
  try {
    switch (language.value) {
      case 'json':
        output.value = formatJson(input.value)
        break
      case 'html':
        output.value = formatHtml(input.value)
        break
      case 'css':
        output.value = formatCss(input.value)
        break
      case 'js':
        output.value = formatJs(input.value)
        break
    }
  } catch (e) {
    errorMsg.value = (e as Error).message || '格式化失败'
    output.value = ''
  }
}

async function copyOutput(): Promise<void> {
  if (!output.value) return
  try {
    await navigator.clipboard.writeText(output.value)
    errorMsg.value = ''
  } catch {
    errorMsg.value = '复制失败：浏览器不支持或权限被拒'
  }
}

function clearAll(): void {
  input.value = ''
  output.value = ''
  errorMsg.value = ''
}
</script>

<template>
  <div class="code-format">
    <div class="code-format__bar">
      <d-select
        v-model="language"
        :options="languageOptions"
        size="sm"
        class="code-format__select"
      />
      <div class="code-format__actions">
        <d-button size="sm" type="primary" :disabled="!canFormat" @click="doFormat">
          格式化
        </d-button>
        <d-button size="sm" :disabled="!output" @click="copyOutput">
          复制
        </d-button>
        <d-button size="sm" type="common" @click="clearAll">清空</d-button>
      </div>
    </div>

    <div v-if="errorMsg" class="code-format__error">{{ errorMsg }}</div>

    <div class="code-format__panes">
      <div class="code-format__pane">
        <label class="code-format__label">输入</label>
        <textarea
          v-model="input"
          class="code-format__textarea"
          placeholder="粘贴待格式化的代码…"
          spellcheck="false"
        />
      </div>
      <div class="code-format__pane">
        <label class="code-format__label">输出</label>
        <textarea
          v-model="output"
          class="code-format__textarea"
          readonly
          spellcheck="false"
          placeholder="格式化结果…"
        />
      </div>
    </div>
  </div>
</template>

<style scoped>
.code-format {
  display: flex;
  flex-direction: column;
  gap: 8px;
  height: 100%;
  min-height: 0;
}

.code-format__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  flex-wrap: wrap;
}

.code-format__select {
  width: 140px;
}

.code-format__actions {
  display: flex;
  gap: 6px;
}

.code-format__error {
  font-size: 12px;
  color: #f53f3f;
  background: #ffece8;
  padding: 4px 8px;
  border-radius: 4px;
}

.code-format__panes {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  flex: 1;
  min-height: 0;
}

.code-format__pane {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  min-height: 0;
}

.code-format__label {
  font-size: 12px;
  color: #86909c;
  font-weight: 500;
}

.code-format__textarea {
  flex: 1;
  min-height: 80px;
  width: 100%;
  resize: none;
  border: 1px solid #e5e6eb;
  border-radius: 6px;
  padding: 8px 10px;
  font-family: 'Menlo', 'Consolas', monospace;
  font-size: 12px;
  line-height: 1.5;
  color: #1c1f23;
  background: #fff;
  outline: none;
  box-sizing: border-box;
}

.code-format__textarea:focus {
  border-color: #1668dc;
  box-shadow: 0 0 0 2px rgba(22, 104, 220, 0.12);
}
</style>
