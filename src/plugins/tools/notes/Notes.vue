<script setup lang="ts">
import { computed } from 'vue'
import { usePluginState } from '@/composables/usePluginState'

type NoteColor = 'yellow' | 'pink' | 'blue' | 'green' | 'purple'

interface NoteItem {
  id: string
  content: string
  color: NoteColor
}

interface NotesState {
  items: NoteItem[]
  [key: string]: unknown
}

const COLORS: { value: NoteColor; label: string; swatch: string }[] = [
  { value: 'yellow', label: '黄', swatch: '#fff9b0' },
  { value: 'pink', label: '粉', swatch: '#ffd6e7' },
  { value: 'blue', label: '蓝', swatch: '#cfe8ff' },
  { value: 'green', label: '绿', swatch: '#cdf5d4' },
  { value: 'purple', label: '紫', swatch: '#e3d4ff' },
]

const { state, patch } = usePluginState<NotesState>('notes', {
  defaultState: () => ({ items: [] }),
})

const items = computed<NoteItem[]>(() => state.value?.items ?? [])

function genId(): string {
  return `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
}

function addNote(): void {
  const next: NoteItem = {
    id: genId(),
    content: '',
    color: 'yellow',
  }
  patch({ items: [...items.value, next] })
}

function updateContent(id: string, content: string): void {
  patch({
    items: items.value.map((it) => (it.id === id ? { ...it, content } : it)),
  })
}

function updateColor(id: string, color: NoteColor): void {
  patch({
    items: items.value.map((it) => (it.id === id ? { ...it, color } : it)),
  })
}

function removeNote(id: string): void {
  patch({ items: items.value.filter((it) => it.id !== id) })
}
</script>

<template>
  <div v-if="state" class="notes">
    <div class="notes__toolbar">
      <span class="notes__count">{{ items.length }} 张便签</span>
      <d-button size="mini" type="primary" @click="addNote">+ 新增便签</d-button>
    </div>
    <div v-if="items.length === 0" class="notes__empty">
      还没有便签，点击「新增便签」开始记录。
    </div>
    <div v-else class="notes__grid">
      <div
        v-for="note in items"
        :key="note.id"
        class="note-card"
        :class="`note-card--${note.color}`"
      >
        <textarea
          class="note-card__content"
          :value="note.content"
          placeholder="写点什么..."
          spellcheck="false"
          @input="updateContent(note.id, ($event.target as HTMLTextAreaElement).value)"
        />
        <div class="note-card__footer">
          <div class="note-card__colors">
            <button
              v-for="c in COLORS"
              :key="c.value"
              type="button"
              class="note-card__color"
              :class="{ 'is-active': note.color === c.value }"
              :style="{ background: c.swatch }"
              :title="`颜色：${c.label}`"
              @click="updateColor(note.id, c.value)"
            />
          </div>
          <button
            type="button"
            class="note-card__remove"
            title="删除"
            @click="removeNote(note.id)"
          >
            ✕
          </button>
        </div>
      </div>
    </div>
  </div>
  <div v-else class="notes notes--loading">加载中…</div>
</template>

<style scoped>
.notes {
  display: flex;
  flex-direction: column;
  height: 100%;
  gap: 10px;
}

.notes--loading {
  color: #86909c;
  font-size: 13px;
  padding: 12px;
}

.notes__toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 4px 4px 6px;
  border-bottom: 1px solid #f2f3f5;
}

.notes__count {
  font-size: 12px;
  color: #86909c;
}

.notes__empty {
  padding: 24px;
  text-align: center;
  color: #86909c;
  font-size: 13px;
}

.notes__grid {
  flex: 1;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 10px;
  overflow: auto;
  padding: 2px;
}

.note-card {
  display: flex;
  flex-direction: column;
  border-radius: 6px;
  padding: 8px;
  min-height: 120px;
  border: 1px solid rgba(0, 0, 0, 0.06);
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}

.note-card--yellow { background: #fff9b0; }
.note-card--pink   { background: #ffd6e7; }
.note-card--blue   { background: #cfe8ff; }
.note-card--green  { background: #cdf5d4; }
.note-card--purple { background: #e3d4ff; }

.note-card__content {
  flex: 1;
  width: 100%;
  border: none;
  background: transparent;
  resize: none;
  outline: none;
  font-size: 13px;
  line-height: 1.5;
  color: #1c1f23;
  font-family: inherit;
}

.note-card__content::placeholder {
  color: rgba(28, 31, 35, 0.4);
}

.note-card__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  margin-top: 6px;
}

.note-card__colors {
  display: flex;
  gap: 4px;
}

.note-card__color {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  border: 1px solid rgba(0, 0, 0, 0.15);
  cursor: pointer;
  padding: 0;
  transition: transform 0.15s;
}

.note-card__color:hover {
  transform: scale(1.15);
}

.note-card__color.is-active {
  border: 2px solid #1c1f23;
  box-shadow: 0 0 0 1px #fff inset;
}

.note-card__remove {
  background: transparent;
  border: none;
  cursor: pointer;
  font-size: 12px;
  color: #86909c;
  width: 20px;
  height: 20px;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
}

.note-card__remove:hover {
  background: rgba(245, 63, 63, 0.15);
  color: #f53f3f;
}
</style>
