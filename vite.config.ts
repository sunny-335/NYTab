import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'node:path'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
    },
  },
  // vue-devui@1.6.36 的 CSS 含 legacy IE hack(*zoom、:before.class 等),
  // Vite 8 默认的 LightningCSS 无法压缩此类不合规语法会抛 SyntaxError,
  // 改用 esbuild 压缩 CSS 以兼容。
  build: {
    cssMinify: 'esbuild',
  },
  server: {
    proxy: {
      // PHP 后端 API
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
      // 后端静态资源(书签图标等)
      '/uploads': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
})
