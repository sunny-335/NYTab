<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { Message } from 'vue-devui'
import {
  useShortcutsStore,
  formatKeys,
  isBlacklisted,
  type Shortcut,
  type ShortcutAction,
} from '@/stores/shortcuts.store'
import ShortcutRecorder from '@/components/ShortcutRecorder.vue'

/**
 * ShortcutSettings — 快捷键设置页。
 *
 * 列表展示所有快捷键(内置 + 自定义),每项显示按键序列(可视化)、
 * 描述、动作类型、编辑/删除按钮(内置不可删除)。
 *
 * 新增/编辑通过 d-modal 弹窗,内含描述、动作类型、动作参数、录制器。
 * 冲突检测和黑名单检测实时进行,标红 + 禁用保存。
 */
const store = useShortcutsStore()

/* --------------------------- 下拉选项 --------------------------- */
const typeOptions: Array<{ value: string; name: string }> = [
  { value: 'sequence', name: '序列键(如 S 或 Z+S)' },
  { value: 'combo', name: '组合键(如 Ctrl+K)' },
]

const actionOptions: Array<{ value: string; name: string }> = [
  { value: 'focus_search', name: '聚焦搜索栏' },
  { value: 'toggle_search_mode', name: '切换站内搜索模式' },
  { value: 'open_url', name: '打开 URL' },
  { value: 'go_path', name: '跳转路径' },
]

/* --------------------------- 动作描述 --------------------------- */
function actionLabel(action: ShortcutAction): string {
  switch (action.kind) {
    case 'focus_search':
      return '聚焦搜索栏'
    case 'toggle_search_mode':
      return '切换站内搜索模式'
    case 'open_url':
      return `打开 URL: ${action.url}`
    case 'go_path':
      return `跳转路径: ${action.path}`
  }
}

/* --------------------------- 弹窗表单 --------------------------- */
const modalVisible = ref(false)

interface FormState {
  id: string | null
  description: string
  type: 'sequence' | 'combo'
  actionKind: string
  actionUrl: string
  actionPath: string
  keys: string[]
}

const form = reactive<FormState>({
  id: null,
  description: '',
  type: 'sequence',
  actionKind: 'focus_search',
  actionUrl: '',
  actionPath: '',
  keys: [],
})

function resetForm(): void {
  form.id = null
  form.description = ''
  form.type = 'sequence'
  form.actionKind = 'focus_search'
  form.actionUrl = ''
  form.actionPath = ''
  form.keys = []
}

function openAdd(): void {
  resetForm()
  modalVisible.value = true
}

function openEdit(shortcut: Shortcut): void {
  form.id = shortcut.id
  form.description = shortcut.description
  form.type = shortcut.type
  form.actionKind = shortcut.action.kind
  form.actionUrl =
    shortcut.action.kind === 'open_url' ? shortcut.action.url : ''
  form.actionPath =
    shortcut.action.kind === 'go_path' ? shortcut.action.path : ''
  form.keys = [...shortcut.keys]
  modalVisible.value = true
}

function onTypeChange(val: string | number | boolean): void {
  form.type = val as 'sequence' | 'combo'
  // 切换类型时清空已录制的按键
  form.keys = []
}

function onActionKindChange(val: string | number | boolean): void {
  form.actionKind = String(val)
}

/* --------------------------- 校验 --------------------------- */
const blacklistMessage = computed(() => {
  if (form.keys.length === 0) return ''
  if (isBlacklisted(form.keys, form.type)) {
    return '该快捷键为系统保留,无法绑定'
  }
  return ''
})

const conflictShortcut = computed(() => {
  if (form.keys.length === 0) return null
  return store.findConflict(
    { keys: form.keys, type: form.type },
    form.id ?? undefined,
  )
})

const conflictMessage = computed(() => {
  if (!conflictShortcut.value) return ''
  return `与已有快捷键冲突: ${conflictShortcut.value.description}`
})

const urlError = computed(() => {
  if (form.actionKind !== 'open_url') return ''
  const u = form.actionUrl.trim()
  if (!u) return 'URL 不能为空'
  if (!/^https?:\/\/.+/i.test(u)) return 'URL 需以 http:// 或 https:// 开头'
  return ''
})

const pathError = computed(() => {
  if (form.actionKind !== 'go_path') return ''
  const p = form.actionPath.trim()
  if (!p) return '路径不能为空'
  if (!p.startsWith('/')) return '路径需以 / 开头'
  return ''
})

const canSave = computed(() => {
  if (form.description.trim() === '') return false
  if (form.keys.length === 0) return false
  if (blacklistMessage.value) return false
  if (conflictMessage.value) return false
  if (form.actionKind === 'open_url' && urlError.value) return false
  if (form.actionKind === 'go_path' && pathError.value) return false
  return true
})

/* --------------------------- 保存 --------------------------- */
function buildAction(): ShortcutAction {
  switch (form.actionKind) {
    case 'focus_search':
      return { kind: 'focus_search' }
    case 'toggle_search_mode':
      return { kind: 'toggle_search_mode' }
    case 'open_url':
      return { kind: 'open_url', url: form.actionUrl.trim() }
    case 'go_path':
      return { kind: 'go_path', path: form.actionPath.trim() }
    default:
      return { kind: 'focus_search' }
  }
}

function handleSave(): void {
  if (!canSave.value) return
  const action = buildAction()
  if (form.id) {
    store.updateShortcut(form.id, {
      description: form.description.trim(),
      type: form.type,
      keys: form.keys,
      action,
    })
    Message.success('快捷键已更新')
  } else {
    const id = `custom-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
    store.addShortcut({
      id,
      description: form.description.trim(),
      type: form.type,
      keys: form.keys,
      action,
      builtin: false,
    })
    Message.success('快捷键已添加')
  }
  modalVisible.value = false
}

/* --------------------------- 删除 / 重置 --------------------------- */
function handleDelete(shortcut: Shortcut): void {
  if (shortcut.builtin) return
  if (!window.confirm(`确定删除快捷键「${shortcut.description}」？`)) return
  store.removeShortcut(shortcut.id)
  Message.success('快捷键已删除')
}

function handleReset(): void {
  if (!window.confirm('确定重置所有快捷键为内置默认？自定义快捷键将被清除。')) {
    return
  }
  store.resetToDefault()
  Message.success('已重置为默认快捷键')
}

/* --------------------------- 初始化 --------------------------- */
onMounted(() => {
  store.init()
})
</script>

<template>
  <div class="shortcut-settings">
    <div class="settings-header">
      <h1 class="page-title">快捷键设置</h1>
      <div class="header-actions">
        <d-button type="common" @click="handleReset">重置为默认</d-button>
        <d-button type="primary" @click="openAdd">+ 新增快捷键</d-button>
      </div>
    </div>

    <div class="shortcut-list">
      <div
        v-for="shortcut in store.shortcuts"
        :key="shortcut.id"
        class="shortcut-row"
      >
        <div class="shortcut-row__keys">
          <kbd class="key-badge">{{ formatKeys(shortcut.keys, shortcut.type) }}</kbd>
        </div>
        <div class="shortcut-row__info">
          <div class="shortcut-row__desc">{{ shortcut.description }}</div>
          <div class="shortcut-row__action">{{ actionLabel(shortcut.action) }}</div>
        </div>
        <div class="shortcut-row__tags">
          <span v-if="shortcut.builtin" class="tag tag--builtin">内置</span>
          <span v-else class="tag tag--custom">自定义</span>
          <span class="tag tag--type">
            {{ shortcut.type === 'sequence' ? '序列键' : '组合键' }}
          </span>
        </div>
        <div class="shortcut-row__actions">
          <button
            type="button"
            class="text-btn"
            @click="openEdit(shortcut)"
          >
            编辑
          </button>
          <button
            v-if="!shortcut.builtin"
            type="button"
            class="text-btn text-btn--danger"
            @click="handleDelete(shortcut)"
          >
            删除
          </button>
        </div>
      </div>
      <div v-if="store.shortcuts.length === 0" class="empty-state">
        暂无快捷键,点击「+ 新增快捷键」创建
      </div>
    </div>

    <!-- 新增/编辑弹窗 -->
    <d-modal
      :model-value="modalVisible"
      :title="form.id ? '编辑快捷键' : '新增快捷键'"
      show-close
      show-overlay
      append-to-body
      @update:model-value="modalVisible = $event"
    >
      <div class="shortcut-form">
        <label class="form-field">
          <span class="form-label">描述 <span class="required">*</span></span>
          <d-input
            v-model="form.description"
            placeholder="如:聚焦搜索栏"
          />
        </label>

        <label class="form-field">
          <span class="form-label">类型</span>
          <d-select
            :model-value="form.type"
            :options="typeOptions"
            placeholder="选择快捷键类型"
            @update:model-value="onTypeChange"
          />
        </label>

        <label class="form-field">
          <span class="form-label">动作类型</span>
          <d-select
            :model-value="form.actionKind"
            :options="actionOptions"
            placeholder="选择动作类型"
            @update:model-value="onActionKindChange"
          />
        </label>

        <label v-if="form.actionKind === 'open_url'" class="form-field">
          <span class="form-label">URL <span class="required">*</span></span>
          <d-input
            v-model="form.actionUrl"
            placeholder="https://example.com"
          />
          <small v-if="urlError" class="field-error">{{ urlError }}</small>
        </label>

        <label v-if="form.actionKind === 'go_path'" class="form-field">
          <span class="form-label">路径 <span class="required">*</span></span>
          <d-input
            v-model="form.actionPath"
            placeholder="/bookmarks"
          />
          <small v-if="pathError" class="field-error">{{ pathError }}</small>
        </label>

        <div class="form-field">
          <span class="form-label">按键录制 <span class="required">*</span></span>
          <ShortcutRecorder
            v-model="form.keys"
            :type="form.type"
            :conflict="conflictMessage"
            :blacklisted="blacklistMessage"
          />
        </div>
      </div>

      <template #footer>
        <d-button type="common" @click="modalVisible = false">取消</d-button>
        <d-button
          type="primary"
          :disabled="!canSave"
          @click="handleSave"
        >
          {{ form.id ? '保存' : '添加' }}
        </d-button>
      </template>
    </d-modal>
  </div>
</template>

<style scoped>
.shortcut-settings {
  max-width: 900px;
}

.settings-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}

.page-title {
  margin: 0;
  font-size: 22px;
  font-weight: 600;
  color: #1c1f23;
}

.header-actions {
  display: flex;
  gap: 8px;
}

/* --------------------------- List --------------------------- */
.shortcut-list {
  background: #fff;
  border: 1px solid #e5e6eb;
  border-radius: 8px;
  overflow: hidden;
}

.shortcut-row {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 14px 16px;
  border-bottom: 1px solid #f2f3f5;
  min-height: 56px;
}

.shortcut-row:last-child {
  border-bottom: none;
}

.shortcut-row:hover {
  background: #f7f8fa;
}

.shortcut-row__keys {
  flex-shrink: 0;
  min-width: 120px;
}

.key-badge {
  display: inline-block;
  padding: 4px 10px;
  background: #f2f3f5;
  border: 1px solid #d9d9d9;
  border-radius: 4px;
  font-family: 'SF Mono', 'Fira Code', 'Consolas', monospace;
  font-size: 13px;
  font-weight: 600;
  color: #1c1f23;
  letter-spacing: 0.5px;
  white-space: nowrap;
}

.shortcut-row__info {
  flex: 1;
  min-width: 0;
}

.shortcut-row__desc {
  font-size: 14px;
  font-weight: 500;
  color: #1c1f23;
  line-height: 1.4;
}

.shortcut-row__action {
  font-size: 12px;
  color: #86909c;
  margin-top: 2px;
  line-height: 1.4;
}

.shortcut-row__tags {
  display: flex;
  gap: 6px;
  flex-shrink: 0;
}

.tag {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: 500;
  line-height: 1.6;
}

.tag--builtin {
  background: #e8f3ff;
  color: #1668dc;
}

.tag--custom {
  background: #fff7e6;
  color: #d4a017;
}

.tag--type {
  background: #f2f3f5;
  color: #4e5969;
}

.shortcut-row__actions {
  display: flex;
  gap: 8px;
  flex-shrink: 0;
}

.text-btn {
  background: none;
  border: none;
  font-size: 13px;
  color: #1668dc;
  cursor: pointer;
  padding: 4px 6px;
  border-radius: 3px;
  transition: background 0.15s;
}

.text-btn:hover {
  background: #e8f3ff;
}

.text-btn--danger {
  color: #f53f3f;
}

.text-btn--danger:hover {
  background: #ffece8;
}

.empty-state {
  padding: 48px 16px;
  text-align: center;
  color: #86909c;
  font-size: 14px;
}

/* --------------------------- Form --------------------------- */
.shortcut-form {
  min-width: 380px;
  max-width: 560px;
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 4px 0;
}

.form-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.form-label {
  font-size: 13px;
  color: #4e5969;
  font-weight: 500;
}

.required {
  color: #f53f3f;
}

.field-error {
  color: #f53f3f;
  font-size: 12px;
}
</style>
