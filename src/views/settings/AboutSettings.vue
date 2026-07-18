<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { brandingApi } from '@/api/branding.api'

/**
 * AboutSettings — 关于子页。
 *
 * - 版本号:从 package.json 同步(hardcode,构建时由 release 脚本更新)
 * - 版权信息:从 GET /api/branding 的 copyright 字段读取(后端硬编码)
 *   即使 GET 失败也回退到「© 暖心向阳335」字面量
 * - 文档链接:VitePress 文档站(未部署时使用 vitepress.dev 占位)
 * - 项目简介:简短描述
 *
 * copyright 字段不可编辑(只读展示),由后端 BrandingService::COPYRIGHT
 * 硬编码,任何 API 都无法修改。
 */
const APP_VERSION = '0.0.0'
const DOCS_URL = 'https://vitepress.dev'
const FALLBACK_COPYRIGHT = '© 暖心向阳335'

const copyright = ref<string>(FALLBACK_COPYRIGHT)
const loading = ref(true)

onMounted(async () => {
  try {
    const branding = await brandingApi.get()
    if (branding.copyright) {
      copyright.value = branding.copyright
    }
  } catch {
    // 拦截器已 toast;使用 fallback copyright 继续渲染
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="about-settings">
    <h1 class="page-title">关于</h1>

    <d-card class="about-card" shadow="hover">
      <template #title>
        <div class="about-head">
          <span class="about-head__name">NYTab</span>
          <span class="about-head__version">v{{ APP_VERSION }}</span>
        </div>
      </template>

      <template #content>
        <div v-if="loading" class="about-loading">加载中…</div>

        <div v-else class="about-body">
          <!-- 项目简介 -->
          <p class="about-section">
            NYTab 是一个基于 Vue 3 + Vite 的多功能标签页与个人效率工作台,
            集成书签管理、搜索引擎、背景、天气、快捷键、开发者模式等可定制能力,
            可作为浏览器起始页或自托管工作台使用。
          </p>

          <!-- 版权信息(只读,不可编辑) -->
          <div class="about-row">
            <span class="about-row__label">版权信息</span>
            <span class="about-row__value about-row__value--readonly">
              {{ copyright }}
            </span>
          </div>

          <!-- 版本 -->
          <div class="about-row">
            <span class="about-row__label">版本号</span>
            <span class="about-row__value">v{{ APP_VERSION }}</span>
          </div>

          <!-- 文档链接 -->
          <div class="about-row">
            <span class="about-row__label">文档</span>
            <a
              :href="DOCS_URL"
              class="about-row__link"
              target="_blank"
              rel="noopener noreferrer"
            >
              {{ DOCS_URL }}
            </a>
          </div>

          <!-- 技术栈 -->
          <div class="about-row">
            <span class="about-row__label">技术栈</span>
            <span class="about-row__value">
              Vue 3 · TypeScript · Vite · Vue DevUI · Pinia · Vue Router
            </span>
          </div>
        </div>
      </template>
    </d-card>
  </div>
</template>

<style scoped>
.about-settings {
  max-width: 720px;
}

.page-title {
  margin: 0 0 24px;
  font-size: 22px;
  font-weight: 600;
  color: #1c1f23;
}

.about-card {
  background: #fff;
  border: 1px solid #e5e6eb;
  border-radius: 8px;
}

.about-head {
  display: flex;
  align-items: baseline;
  gap: 12px;
}

.about-head__name {
  font-size: 20px;
  font-weight: 700;
  color: #1c1f23;
}

.about-head__version {
  font-size: 13px;
  color: #86909c;
  font-family: 'SF Mono', 'Fira Code', 'Consolas', monospace;
  background: #f2f3f5;
  padding: 2px 8px;
  border-radius: 10px;
}

.about-loading {
  padding: 16px 0;
  text-align: center;
  color: #86909c;
  font-size: 14px;
}

.about-body {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 8px 0;
}

.about-section {
  margin: 0;
  font-size: 14px;
  color: #4e5969;
  line-height: 1.7;
}

.about-row {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  padding: 8px 0;
  border-top: 1px solid #f2f3f5;
}

.about-row__label {
  font-size: 13px;
  color: #86909c;
  font-weight: 500;
  min-width: 80px;
  flex-shrink: 0;
}

.about-row__value {
  font-size: 14px;
  color: #1c1f23;
  word-break: break-all;
}

.about-row__value--readonly {
  color: #4e5969;
  background: #f7f8fa;
  border: 1px solid #e5e6eb;
  border-radius: 6px;
  padding: 4px 10px;
  font-weight: 500;
}

.about-row__link {
  font-size: 14px;
  color: #1668dc;
  text-decoration: none;
  word-break: break-all;
}

.about-row__link:hover {
  text-decoration: underline;
}
</style>
