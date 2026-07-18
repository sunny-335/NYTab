<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { usePluginState } from '@/composables/usePluginState'

/**
 * Regex — 正则表达式实时匹配测试工具。
 *
 * - 输入：模式、标志（g/i/m）、待测试字符串
 * - 预设常用正则（邮箱、URL、IPv4、手机号）
 * - 输出：用 <mark> 高亮所有匹配项；表格列出每个匹配的捕获组
 * - try/catch 处理无效正则，显示错误信息
 *
 * 通过 usePluginState 持久化用户输入。
 */
interface RegexState {
  pattern: string
  flags: string
  testString: string
  preset: string
  [key: string]: unknown
}

interface MatchGroup {
  index: number
  match: string
  start: number
  end: number
  groups: (string | undefined)[]
}

const { state } = usePluginState<RegexState>('regex', {
  defaultState: () => ({ pattern: '', flags: 'g', testString: '', preset: '' }),
})

const pattern = ref<string>(state.value?.pattern ?? '')
const flags = ref<string>(state.value?.flags ?? 'g')
const testString = ref<string>(state.value?.testString ?? '')
const preset = ref<string>(state.value?.preset ?? '')

const presetOptions = [
  { name: '— 自定义 —', value: '' },
  { name: '邮箱', value: 'email' },
  { name: 'URL', value: 'url' },
  { name: 'IPv4', value: 'ipv4' },
  { name: '手机号', value: 'phone' },
]

const PRESETS: Record<string, { pattern: string; flags: string }> = {
  email: { pattern: '^\\w[\\w.-]*@[\\w.-]+\\.\\w+$', flags: 'gm' },
  url: { pattern: 'https?://[\\w\\-]+(\\.[\\w\\-]+)+[\\w.,@?^=%&:/~+#-]*', flags: 'g' },
  ipv4: { pattern: '\\b(?:\\d{1,3}\\.){3}\\d{1,3}\\b', flags: 'g' },
  phone: { pattern: '^1\\d{10}$', flags: 'gm' },
}

watch([pattern, flags, testString, preset], () => {
  if (!state.value) return
  state.value.pattern = pattern.value
  state.value.flags = flags.value
  state.value.testString = testString.value
  state.value.preset = preset.value
})

const errorMsg = ref<string>('')

const matches = computed<MatchGroup[]>(() => {
  errorMsg.value = ''
  if (!pattern.value) return []
  const flagStr = flags.value.includes('g') ? flags.value : flags.value + 'g'
  try {
    const re = new RegExp(pattern.value, flagStr)
    const result: MatchGroup[] = []
    let m: RegExpExecArray | null
    let safety = 0
    while ((m = re.exec(testString.value)) !== null) {
      result.push({
        index: result.length,
        match: m[0],
        start: m.index,
        end: m.index + m[0].length,
        groups: m.slice(1),
      })
      if (m[0] === '') {
        // 避免零宽匹配死循环
        re.lastIndex++
      }
      if (++safety > 10000) break
    }
    return result
  } catch (e) {
    errorMsg.value = '正则无效：' + (e as Error).message
    return []
  }
})

/** 用 <mark> 包裹匹配项后的高亮 HTML（已转义非匹配部分）。 */
const highlightedHtml = computed<string>(() => {
  const list = matches.value
  if (list.length === 0) return escapeHtml(testString.value)
  let out = ''
  let cursor = 0
  for (const m of list) {
    out += escapeHtml(testString.value.slice(cursor, m.start))
    out += '<mark>' + escapeHtml(m.match) + '</mark>'
    cursor = m.end
  }
  out += escapeHtml(testString.value.slice(cursor))
  return out
})

function escapeHtml(str: string): string {
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

function onPresetChange(val: string | number): void {
  preset.value = String(val)
  if (val && PRESETS[val]) {
    pattern.value = PRESETS[val].pattern
    flags.value = PRESETS[val].flags
  }
}
</script>

<template>
  <div class="regex">
    <div class="regex__form">
      <div class="regex__row">
        <label class="regex__field regex__field--pattern">
          <span>正则模式</span>
          <d-input
            v-model="pattern"
            placeholder="例如 \d+"
            size="sm"
          />
        </label>
        <label class="regex__field regex__field--flags">
          <span>标志</span>
          <d-input
            v-model="flags"
            placeholder="gim"
            size="sm"
          />
        </label>
        <label class="regex__field regex__field--preset">
          <span>预设</span>
          <d-select
            :model-value="preset"
            :options="presetOptions"
            size="sm"
            @update:model-value="onPresetChange"
          />
        </label>
      </div>
      <label class="regex__field">
        <span>测试字符串</span>
        <textarea
          v-model="testString"
          class="regex__textarea"
          placeholder="输入待匹配文本…"
          spellcheck="false"
        />
      </label>
    </div>

    <div v-if="errorMsg" class="regex__error">{{ errorMsg }}</div>

    <div class="regex__result">
      <div class="regex__section">
        <div class="regex__section-title">
          匹配高亮
          <span class="regex__count">共 {{ matches.length }} 项</span>
        </div>
        <pre
          v-if="testString"
          class="regex__highlight"
          v-html="highlightedHtml"
        />
        <div v-else class="regex__empty">无测试文本</div>
      </div>

      <div class="regex__section">
        <div class="regex__section-title">捕获组列表</div>
        <div v-if="matches.length === 0" class="regex__empty">无匹配项</div>
        <table v-else class="regex__table">
          <thead>
            <tr>
              <th>#</th>
              <th>匹配</th>
              <th>位置</th>
              <th>捕获组</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in matches" :key="m.index">
              <td>{{ m.index + 1 }}</td>
              <td class="regex__mono">{{ m.match || '(空)' }}</td>
              <td>{{ m.start }}–{{ m.end }}</td>
              <td class="regex__mono">
                <template v-if="m.groups.length === 0">—</template>
                <template v-else>
                  <span v-for="(g, i) in m.groups" :key="i" class="regex__group">
                    [{{ i + 1 }}] {{ g ?? '(undefined)' }}
                  </span>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.regex {
  display: flex;
  flex-direction: column;
  gap: 10px;
  height: 100%;
  min-height: 0;
}

.regex__form {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.regex__row {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.regex__field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.regex__field > span {
  font-size: 12px;
  color: #86909c;
  font-weight: 500;
}

.regex__field--pattern {
  flex: 1 1 200px;
}

.regex__field--flags {
  width: 80px;
}

.regex__field--preset {
  width: 140px;
}

.regex__textarea {
  width: 100%;
  min-height: 80px;
  resize: vertical;
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

.regex__textarea:focus {
  border-color: #1668dc;
  box-shadow: 0 0 0 2px rgba(22, 104, 220, 0.12);
}

.regex__error {
  font-size: 12px;
  color: #f53f3f;
  background: #ffece8;
  padding: 4px 8px;
  border-radius: 4px;
}

.regex__result {
  display: flex;
  flex-direction: column;
  gap: 10px;
  flex: 1;
  min-height: 0;
  overflow: auto;
}

.regex__section {
  border: 1px solid #e5e6eb;
  border-radius: 6px;
  padding: 8px 10px;
  background: #fff;
}

.regex__section-title {
  font-size: 12px;
  font-weight: 600;
  color: #4e5969;
  margin-bottom: 6px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.regex__count {
  font-weight: 400;
  color: #86909c;
}

.regex__highlight {
  margin: 0;
  font-family: 'Menlo', 'Consolas', monospace;
  font-size: 12px;
  line-height: 1.6;
  color: #1c1f23;
  white-space: pre-wrap;
  word-break: break-all;
  max-height: 200px;
  overflow: auto;
}

.regex__highlight :deep(mark) {
  background: #fff3b0;
  color: #1c1f23;
  border-radius: 2px;
  padding: 0 1px;
}

.regex__empty {
  font-size: 12px;
  color: #86909c;
  padding: 6px 0;
}

.regex__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}

.regex__table th,
.regex__table td {
  border: 1px solid #f2f3f5;
  padding: 4px 8px;
  text-align: left;
  vertical-align: top;
}

.regex__table th {
  background: #f7f8fa;
  font-weight: 600;
  color: #4e5969;
}

.regex__mono {
  font-family: 'Menlo', 'Consolas', monospace;
  word-break: break-all;
}

.regex__group {
  display: inline-block;
  margin-right: 8px;
}
</style>
