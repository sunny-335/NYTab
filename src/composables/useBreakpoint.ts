import { ref, onMounted, onUnmounted, type Ref } from 'vue'

/** 768px 断点:≥768 视为 PC,<768 视为移动端。 */
const BREAKPOINT = 768

/**
 * 响应式设备断点检测。
 *
 * 通过监听 window resize 事件维护 `isMobile` / `isDesktop` 两个 ref。
 * ≥768px 为 PC,<768px 为移动端。在 SSR / 非浏览器环境安全降级为 PC。
 *
 * @returns `{ isMobile, isDesktop }`
 */
export function useBreakpoint(): {
  isMobile: Ref<boolean>
  isDesktop: Ref<boolean>
} {
  const isMobile = ref(false)
  const isDesktop = ref(true)

  function update(): void {
    if (typeof window === 'undefined') return
    const mobile = window.innerWidth < BREAKPOINT
    isMobile.value = mobile
    isDesktop.value = !mobile
  }

  onMounted(() => {
    update()
    window.addEventListener('resize', update)
  })

  onUnmounted(() => {
    if (typeof window !== 'undefined') {
      window.removeEventListener('resize', update)
    }
  })

  return { isMobile, isDesktop }
}
