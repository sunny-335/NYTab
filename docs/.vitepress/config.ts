import { defineConfig } from 'vitepress'

export default defineConfig({
  lang: 'zh-CN',
  title: 'NYTab 文档',
  description: '多功能标签页与个人效率工作台',
  lastUpdated: true,
  cleanUrls: true,
  themeConfig: {
    nav: [
      { text: '首页', link: '/' },
      {
        text: '指南',
        items: [
          { text: '快速开始', link: '/guide/getting-started' },
          { text: '快捷键', link: '/guide/shortcuts' },
          { text: '品牌自定义', link: '/guide/brand-customization' },
          { text: '自定义背景', link: '/guide/custom-background' },
          { text: '搜索引擎', link: '/guide/search-engines' }
        ]
      },
      {
        text: '部署',
        items: [
          { text: 'Docker 部署', link: '/deploy/docker' },
          { text: '手工部署', link: '/deploy/manual' }
        ]
      },
      {
        text: '开发',
        items: [
          { text: '开发者模式', link: '/dev/developer-mode' },
          { text: '插件开发', link: '/dev/plugin-development' }
        ]
      },
      {
        text: 'API 密钥',
        items: [
          { text: '高德天气', link: '/api-keys/amap-weather' },
          { text: '和风天气', link: '/api-keys/qweather' }
        ]
      }
    ],
    sidebar: {
      '/guide/': [
        {
          text: '指南',
          items: [
            { text: '快速开始', link: '/guide/getting-started' },
            { text: '快捷键', link: '/guide/shortcuts' },
            { text: '品牌自定义', link: '/guide/brand-customization' },
            { text: '自定义背景', link: '/guide/custom-background' },
            { text: '搜索引擎', link: '/guide/search-engines' }
          ]
        }
      ],
      '/deploy/': [
        {
          text: '部署',
          items: [
            { text: 'Docker 部署', link: '/deploy/docker' },
            { text: '手工部署', link: '/deploy/manual' }
          ]
        }
      ],
      '/dev/': [
        {
          text: '开发',
          items: [
            { text: '开发者模式', link: '/dev/developer-mode' },
            { text: '插件开发', link: '/dev/plugin-development' }
          ]
        }
      ],
      '/api-keys/': [
        {
          text: 'API 密钥',
          items: [
            { text: '高德天气', link: '/api-keys/amap-weather' },
            { text: '和风天气', link: '/api-keys/qweather' }
          ]
        }
      ]
    },
    outline: {
      label: '本页目录',
      level: [2, 3]
    },
    docFooter: {
      prev: '上一页',
      next: '下一页'
    },
    lastUpdated: {
      text: '最后更新于'
    },
    darkModeSwitchLabel: '主题',
    sidebarMenuLabel: '菜单',
    returnToTopLabel: '回到顶部',
    langMenuLabel: '语言',
    search: {
      provider: 'local',
      options: {
        translations: {
          button: {
            buttonText: '搜索文档',
            buttonAriaLabel: '搜索文档'
          },
          modal: {
            noResultsText: '无法找到相关结果',
            resetButtonTitle: '清除查询条件',
            footer: {
              selectText: '选择',
              navigateText: '切换'
            }
          }
        }
      }
    }
  }
})
