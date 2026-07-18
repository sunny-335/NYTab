import { defineToolPlugin } from '../types'

export default defineToolPlugin({
  meta: {
    pluginId: 'markdown',
    name: 'Markdown 编辑器',
    category: 'efficiency',
    icon: '📝',
    description: '实时预览 Markdown 编辑器',
    defaultSize: { w: 8, h: 6 },
    minSize: { w: 4, h: 3 },
  },
  component: () => import('./MarkdownEditor.vue'),
  defaultState: () => ({
    content: '# 欢迎使用 Markdown 编辑器\n\n开始你的笔记...\n\n- 列表项一\n- 列表项二\n\n**加粗** 与 *斜体* 与 `行内代码`。\n\n[链接](https://vue-devui.github.io/)\n',
  }),
})
