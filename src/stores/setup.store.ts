import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { setupApi } from '@/api/setup.api'
import type {
  Requirements,
  DbConfig,
  InstallPayload,
  TestDbResult,
} from '@/api/setup.api'

/**
 * Installation-wizard state.
 *
 * `installed` is tri-state: `null` = not yet checked, `true`/`false` = the
 * backend's answer. The router guard reads this to decide whether to force
 * /setup or /login.
 */
export const useSetupStore = defineStore('setup', () => {
  const installed = ref<boolean | null>(null)
  const version = ref<string | null>(null)
  const requirements = ref<Requirements>({})
  const loading = ref(false)
  const error = ref<string | null>(null)

  /** Result of the most recent /setup/test-database call; null = untested. */
  const dbTestResult = ref<TestDbResult | null>(null)

  /** True when every environment requirement passes. */
  const requirementsOk = computed(() => {
    const items = requirements.value
    const keys = Object.keys(items)
    if (keys.length === 0) return false
    return keys.every((k) => items[k]?.ok === true)
  })

  async function fetchStatus() {
    loading.value = true
    error.value = null
    try {
      const data = await setupApi.status()
      installed.value = data.installed
      if (data.requirements) {
        requirements.value = data.requirements
      }
      if (data.version) {
        version.value = data.version
      }
    } catch (e) {
      error.value = e instanceof Error ? e.message : '无法获取安装状态'
    } finally {
      loading.value = false
    }
  }

  async function testDatabase(db: DbConfig): Promise<boolean> {
    loading.value = true
    error.value = null
    try {
      const result = await setupApi.testDatabase(db)
      dbTestResult.value = result
      return true
    } catch (e) {
      error.value = e instanceof Error ? e.message : '数据库连接测试失败'
      dbTestResult.value = null
      return false
    } finally {
      loading.value = false
    }
  }

  async function install(payload: InstallPayload): Promise<boolean> {
    loading.value = true
    error.value = null
    try {
      await setupApi.install(payload)
      installed.value = true
      return true
    } catch (e) {
      error.value = e instanceof Error ? e.message : '安装失败'
      return false
    } finally {
      loading.value = false
    }
  }

  return {
    installed,
    version,
    requirements,
    loading,
    error,
    dbTestResult,
    requirementsOk,
    fetchStatus,
    testDatabase,
    install,
  }
})
