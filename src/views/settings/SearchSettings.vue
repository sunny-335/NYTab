<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { Message } from 'vue-devui'
import { useSearchStore } from '@/stores/search.store'
import type { SearchStrategy, SearchMode } from '@/stores/search.store'
import { SEARCH_ENGINES } from '@/composables/useSearchEngines'

/**
 * SearchSettings — 搜索引擎设置子页。
 *
 * 包含两组 d-radio-group:
 *  1. 引擎记忆策略:restore(每次启动恢复 Bing)/ remember(记住上次选择)
 *  2. 搜索模式:mixed(默认,书签+联想)/ suggestions(仅联想)/ bookmarks(仅书签)
 *
 * 选择即生效:调用 search.store 的 setStrategy / setSearchMode,
 * store 内部已自动持久化到 localStorage。
 */
const searchStore = useSearchStore()

/** 通过 computed setter 让 d-radio-group 的 v-model 直接驱动 store action。 */
const strategy = computed<SearchStrategy>({
  get: () => searchStore.strategy,
  set: (val) => {
    if (val !== searchStore.strategy) {
      searchStore.setStrategy(val)
      Message.success(
        val === 'restore'
          ? '已切换为:每次启动恢复 Bing'
          : '已切换为:记住上次选择的引擎',
      )
    }
  },
})

const searchMode = computed<SearchMode>({
  get: () => searchStore.searchMode,
  set: (val) => {
    if (val !== searchStore.searchMode) {
      searchStore.setSearchMode(val)
      Message.success('搜索模式已更新')
    }
  },
})

onMounted(() => {
  // 兜底初始化(若 App 启动时未调用 searchStore.init())
  searchStore.init()
})
</script>

<template>
  <div class="search-settings">
    <!-- 引擎记忆策略 -->
    <section class="settings-card">
      <h2 class="settings-card__title">搜索引擎记忆策略</h2>
      <p class="settings-card__desc">
        选择每次启动 NYTab 时,搜索栏使用哪个搜索引擎。
      </p>
      <d-radio-group v-model="strategy" direction="column" class="settings-card__group">
        <d-radio value="restore">
          <span class="radio-label">恢复默认(每次启动使用 Bing)</span>
          <span class="radio-desc">适合多用户共享设备的场景。</span>
        </d-radio>
        <d-radio value="remember">
          <span class="radio-label">记住我上次的选择</span>
          <span class="radio-desc">切换引擎后下次启动仍保留该引擎。</span>
        </d-radio>
      </d-radio-group>
    </section>

    <!-- 搜索模式 -->
    <section class="settings-card">
      <h2 class="settings-card__title">搜索栏建议模式</h2>
      <p class="settings-card__desc">
        控制在搜索栏输入时,建议下拉中显示的内容。
      </p>
      <d-radio-group v-model="searchMode" direction="column" class="settings-card__group">
        <d-radio value="mixed">
          <span class="radio-label">混合模式(推荐)</span>
          <span class="radio-desc">同时显示书签结果(最多 5 条)与搜索联想(8 条)。</span>
        </d-radio>
        <d-radio value="suggestions">
          <span class="radio-label">仅搜索联想</span>
          <span class="radio-desc">只显示 Bing 搜索联想,不显示书签结果。</span>
        </d-radio>
        <d-radio value="bookmarks">
          <span class="radio-label">仅书签</span>
          <span class="radio-desc">只显示匹配的书签结果,不显示搜索联想。</span>
        </d-radio>
      </d-radio-group>
    </section>

    <!-- 内置引擎清单(只读展示) -->
    <section class="settings-card">
      <h2 class="settings-card__title">内置搜索引擎</h2>
      <p class="settings-card__desc">点击搜索栏左侧引擎图标即可切换。</p>
      <ul class="engine-list">
        <li
          v-for="engine in SEARCH_ENGINES"
          :key="engine.id"
          class="engine-list__item"
          :class="{ 'is-current': engine.id === searchStore.currentEngine.id }"
        >
          <span class="engine-list__icon">{{ engine.icon }}</span>
          <span class="engine-list__name">{{ engine.name }}</span>
          <span v-if="engine.id === searchStore.currentEngine.id" class="engine-list__badge">当前</span>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.search-settings {
  display: flex;
  flex-direction: column;
  gap: 20px;
  max-width: 640px;
}

.settings-card {
  background: #fff;
  border: 1px solid #e5e6eb;
  border-radius: 8px;
  padding: 24px;
}

.settings-card__title {
  margin: 0 0 8px;
  font-size: 16px;
  font-weight: 600;
  color: #1c1f23;
}

.settings-card__desc {
  margin: 0 0 16px;
  font-size: 13px;
  color: #86909c;
  line-height: 1.5;
}

.settings-card__group {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

/* d-radio 内部为 label,我们在 slot 里用 .radio-label / .radio-desc 排版 */
.radio-label {
  display: block;
  font-size: 14px;
  font-weight: 500;
  color: #1c1f23;
}

.radio-desc {
  display: block;
  margin-top: 2px;
  font-size: 12px;
  color: #86909c;
  line-height: 1.4;
}

.engine-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 8px;
}

.engine-list__item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border: 1px solid #e5e6eb;
  border-radius: 6px;
  font-size: 14px;
  color: #4e5969;
  background: #fafbfc;
}

.engine-list__item.is-current {
  border-color: #1668dc;
  background: #e8f3ff;
  color: #1668dc;
}

.engine-list__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  background: #fff;
  border-radius: 4px;
  font-size: 13px;
  font-weight: 700;
  color: #1c1f23;
  flex-shrink: 0;
}

.engine-list__item.is-current .engine-list__icon {
  background: #1668dc;
  color: #fff;
}

.engine-list__name {
  flex: 1;
  min-width: 0;
}

.engine-list__badge {
  font-size: 11px;
  color: #1668dc;
  background: #fff;
  border: 1px solid #1668dc;
  border-radius: 10px;
  padding: 1px 8px;
  line-height: 1.5;
}
</style>
