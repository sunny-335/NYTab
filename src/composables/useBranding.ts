import { ref, watch } from 'vue'
import { brandingApi } from '@/api/branding.api'

/**
 * useBranding — 全站品牌信息 composable。
 *
 * 后端 `GET /api/branding` 是 public 接口,未登录/未安装也可调用。
 * 模块级 ref 作为全局单例缓存,首次 `load()` 后所有调用方共享同一份响应式状态,
 * 品牌信息更新后(如 settings 页修改 nickname/title/logo)实时同步到顶部菜单、
 * 登录页、浏览器标签页 title 等所有消费点。
 *
 * - nickname: 应用昵称(用于顶部菜单、登录页大标题)
 * - title:    浏览器标签页 document.title
 * - logo:     logo 图片 URL
 * - copyright: 版权信息(后端只读)
 */
const nickname = ref('NYTab')
const title = ref('NYTab')
const logo = ref('/logo.jpg')
const copyright = ref('© 暖心向阳335')

/** 是否已完成首次加载(避免重复请求)。 */
let loaded = false

/**
 * 同步浏览器标签页 title。
 * 模块级 watcher:仅注册一次,避免多次调用 useBranding() 时创建重复 watcher。
 */
watch(
  title,
  (v) => {
    if (v) document.title = v
  },
  { immediate: true },
)

export function useBranding() {
  /**
   * 拉取品牌信息。public 接口,任何路由都可调用。
   * 仅首次调用真正发请求,后续调用直接返回缓存的 ref。
   */
  async function load() {
    if (loaded) return
    try {
      const data = await brandingApi.get()
      nickname.value = data.nickname
      title.value = data.title
      logo.value = data.logo
      copyright.value = data.copyright
    } catch {
      // 拉取失败保持默认值;拦截器已 toast
    }
    loaded = true
  }

  return { nickname, title, logo, copyright, load }
}
