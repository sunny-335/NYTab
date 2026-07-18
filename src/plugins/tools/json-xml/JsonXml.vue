<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { usePluginState } from '@/composables/usePluginState'

/**
 * JsonXml — JSON 与 XML 双向转换。
 *
 * 自实现简易转换：
 * - JSON → XML：递归遍历，对象 → `<key>...</key>`，数组 → 多个 `<item>...</item>`
 * - XML → JSON：用 DOMParser 解析 XML，递归转对象
 *
 * 通过 usePluginState 持久化方向与输入内容。
 */
type Direction = 'json2xml' | 'xml2json'

interface JsonXmlState {
  direction: Direction
  input: string
  [key: string]: unknown
}

const { state } = usePluginState<JsonXmlState>('json-xml', {
  defaultState: () => ({ direction: 'json2xml', input: '' }),
})

const direction = ref<Direction>(state.value?.direction ?? 'json2xml')
const input = ref<string>(state.value?.input ?? '')
const output = ref<string>('')
const errorMsg = ref<string>('')

const canConvert = computed(() => input.value.trim().length > 0)

watch(direction, (val) => {
  if (state.value) state.value.direction = val
})

watch(input, (val) => {
  if (state.value) state.value.input = val
})

function escapeXml(str: string): string {
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;')
}

function isXmlNameValid(name: string): boolean {
  return /^[A-Za-z_][A-Za-z0-9_\-.]*$/.test(name)
}

/** 将任意 JSON 值转换为 XML 字符串（不含外层包裹标签）。 */
function jsonValueToXml(value: unknown, key: string, indent: number): string {
  const pad = '  '.repeat(indent)
  const safeKey = isXmlNameValid(key) ? key : 'item'

  if (value === null || value === undefined) {
    return `${pad}<${safeKey} />`
  }

  if (Array.isArray(value)) {
    return value
      .map((item) => jsonValueToXml(item, key, indent))
      .join('\n')
  }

  if (typeof value === 'object') {
    const inner = Object.entries(value as Record<string, unknown>)
      .map(([k, v]) => jsonValueToXml(v, k, indent + 1))
      .join('\n')
    return `${pad}<${safeKey}>\n${inner}\n${pad}</${safeKey}>`
  }

  // 基础类型：string / number / boolean
  return `${pad}<${safeKey}>${escapeXml(String(value))}</${safeKey}>`
}

function jsonToXml(jsonStr: string): string {
  const data = JSON.parse(jsonStr)
  if (typeof data !== 'object' || data === null || Array.isArray(data)) {
    throw new Error('JSON 顶层必须是对象')
  }
  const body = Object.entries(data as Record<string, unknown>)
    .map(([k, v]) => jsonValueToXml(v, k, 1))
    .join('\n')
  return `<?xml version="1.0" encoding="UTF-8"?>\n<root>\n${body}\n</root>`
}

/** 将单个 Element 节点递归转为 JS 值。 */
function elementToJson(el: Element): unknown {
  const children = Array.from(el.children)
  if (children.length === 0) {
    const text = el.textContent?.trim() ?? ''
    return text
  }

  // 同名子节点合并为数组
  const grouped: Record<string, Element[]> = {}
  for (const child of children) {
    if (!grouped[child.tagName]) grouped[child.tagName] = []
    grouped[child.tagName].push(child)
  }

  const obj: Record<string, unknown> = {}
  for (const [tag, items] of Object.entries(grouped)) {
    if (items.length === 1) {
      obj[tag] = elementToJson(items[0])
    } else {
      obj[tag] = items.map((it) => elementToJson(it))
    }
  }
  return obj
}

function xmlToJson(xmlStr: string): string {
  const parser = new DOMParser()
  const doc = parser.parseFromString(xmlStr, 'application/xml')
  const parseError = doc.querySelector('parsererror')
  if (parseError) {
    throw new Error('XML 解析失败：' + (parseError.textContent || '格式错误'))
  }
  const root = doc.documentElement
  if (!root) throw new Error('XML 缺少根元素')
  const obj: Record<string, unknown> = {
    [root.tagName]: elementToJson(root),
  }
  return JSON.stringify(obj, null, 2)
}

function doConvert(): void {
  errorMsg.value = ''
  if (!input.value.trim()) {
    output.value = ''
    return
  }
  try {
    if (direction.value === 'json2xml') {
      output.value = jsonToXml(input.value)
    } else {
      output.value = xmlToJson(input.value)
    }
  } catch (e) {
    errorMsg.value = (e as Error).message || '转换失败'
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
  <div class="json-xml">
    <div class="json-xml__bar">
      <d-radio-group
        v-model="direction"
        direction="row"
        class="json-xml__radio"
      >
        <d-radio value="json2xml">JSON → XML</d-radio>
        <d-radio value="xml2json">XML → JSON</d-radio>
      </d-radio-group>
      <div class="json-xml__actions">
        <d-button size="sm" type="primary" :disabled="!canConvert" @click="doConvert">
          转换
        </d-button>
        <d-button size="sm" :disabled="!output" @click="copyOutput">
          复制
        </d-button>
        <d-button size="sm" type="common" @click="clearAll">清空</d-button>
      </div>
    </div>

    <div v-if="errorMsg" class="json-xml__error">{{ errorMsg }}</div>

    <div class="json-xml__panes">
      <div class="json-xml__pane">
        <label class="json-xml__label">
          {{ direction === 'json2xml' ? 'JSON 输入' : 'XML 输入' }}
        </label>
        <textarea
          v-model="input"
          class="json-xml__textarea"
          :placeholder="direction === 'json2xml' ? '粘贴 JSON…' : '粘贴 XML…'"
          spellcheck="false"
        />
      </div>
      <div class="json-xml__pane">
        <label class="json-xml__label">
          {{ direction === 'json2xml' ? 'XML 输出' : 'JSON 输出' }}
        </label>
        <textarea
          v-model="output"
          class="json-xml__textarea"
          readonly
          spellcheck="false"
          placeholder="转换结果…"
        />
      </div>
    </div>
  </div>
</template>

<style scoped>
.json-xml {
  display: flex;
  flex-direction: column;
  gap: 8px;
  height: 100%;
  min-height: 0;
}

.json-xml__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  flex-wrap: wrap;
}

.json-xml__radio {
  display: flex;
  align-items: center;
  gap: 16px;
}

.json-xml__actions {
  display: flex;
  gap: 6px;
}

.json-xml__error {
  font-size: 12px;
  color: #f53f3f;
  background: #ffece8;
  padding: 4px 8px;
  border-radius: 4px;
}

.json-xml__panes {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  flex: 1;
  min-height: 0;
}

.json-xml__pane {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  min-height: 0;
}

.json-xml__label {
  font-size: 12px;
  color: #86909c;
  font-weight: 500;
}

.json-xml__textarea {
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

.json-xml__textarea:focus {
  border-color: #1668dc;
  box-shadow: 0 0 0 2px rgba(22, 104, 220, 0.12);
}
</style>
