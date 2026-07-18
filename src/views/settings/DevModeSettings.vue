<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { Message } from 'vue-devui'
import { devModeApi } from '@/api/dev-mode.api'

/**
 * DevModeSettings — 开发者模式设置子页。
 *
 * - GET /api/dev-mode/status 加载当前 enabled 状态
 * - 开启 → POST /api/dev-mode/enable(后端写入 .env + 跑 SQLite 迁移)
 * - 关闭 → POST /api/dev-mode/disable(后端只切回 PostgreSQL,SQLite 文件保留)
 * - 三条警告说明(d-alert):
 *     1) 强制使用本地 SQLite,即使已配置 PostgreSQL
 *     2) 开发模式下用户名 / 密码均为 admin
 *     3) 生产环境请关闭开发者模式
 *
 * d-switch 的 modelValue 为 string | number | boolean,统一归一为 boolean。
 */
const enabled = ref(false)
const loading = ref(true)
const toggling = ref(false)

onMounted(async () => {
  loading.value = true
  try {
    const status = await devModeApi.status()
    enabled.value = status.enabled
  } catch {
    // 拦截器已 toast
  } finally {
    loading.value = false
  }
})

async function onToggle(val: string | number | boolean): Promise<void> {
  const next = val === true || val === 'true'
  if (next === enabled.value || toggling.value) return

  toggling.value = true
  try {
    if (next) {
      await devModeApi.enable()
      enabled.value = true
      Message.success('开发者模式已开启')
    } else {
      await devModeApi.disable()
      enabled.value = false
      Message.success('开发者模式已关闭')
    }
  } catch {
    // 拦截器已 toast;保持原状态(switch 由 model-value 回滚)
  } finally {
    toggling.value = false
  }
}
</script>

<template>
  <div class="devmode-settings">
    <h1 class="page-title">开发者选项</h1>

    <!-- 开关 -->
    <section class="settings-card">
      <div class="switch-row">
        <div class="switch-row__info">
          <div class="switch-row__label">开发者模式</div>
          <div class="switch-row__desc">
            开启后将切换到本地 SQLite 临时数据库,便于开发调试。
          </div>
        </div>
        <d-switch
          :model-value="enabled"
          :disabled="loading || toggling"
          @update:model-value="onToggle"
        />
      </div>

      <!-- 当前状态指示 -->
      <div
        v-if="!loading"
        class="status-badge"
        :class="enabled ? 'status-badge--on' : 'status-badge--off'"
      >
        <span class="status-badge__dot" />
        <span>{{ enabled ? '已开启' : '已关闭' }}</span>
      </div>
    </section>

    <!-- 警告说明(始终显示) -->
    <section class="settings-card">
      <h2 class="card-title">注意事项</h2>

      <d-alert
        type="warning"
        :closeable="false"
        class="devmode-alert"
      >
        开启开发者模式将使用本地 SQLite 临时数据库,即使已配置 PostgreSQL 也强制使用 SQLite。
      </d-alert>

      <d-alert
        type="warning"
        :closeable="false"
        class="devmode-alert"
      >
        开发模式下用户名和密码均为 admin。
      </d-alert>

      <d-alert
        type="warning"
        :closeable="false"
        class="devmode-alert"
      >
        如需生产环境使用请关闭开发者模式。
      </d-alert>
    </section>

    <!-- 开启时的额外醒目提示 -->
    <section v-if="enabled" class="settings-card">
      <h2 class="card-title">当前状态</h2>
      <d-alert
        type="error"
        :closeable="false"
        class="devmode-alert"
      >
        开发者模式已开启!当前正在使用 SQLite 临时数据库,登录账号为
        admin / admin。请勿用于生产环境。
      </d-alert>
    </section>
  </div>
</template>

<style scoped>
.devmode-settings {
  max-width: 720px;
}

.page-title {
  margin: 0 0 24px;
  font-size: 22px;
  font-weight: 600;
  color: #1c1f23;
}

.settings-card {
  background: #fff;
  border: 1px solid #e5e6eb;
  border-radius: 8px;
  padding: 24px;
  margin-bottom: 20px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.card-title {
  margin: 0;
  font-size: 16px;
  font-weight: 600;
  color: #1c1f23;
}

/* 开关行 */
.switch-row {
  display: flex;
  align-items: center;
  gap: 16px;
  justify-content: space-between;
}

.switch-row__info {
  flex: 1;
}

.switch-row__label {
  font-size: 14px;
  font-weight: 500;
  color: #1c1f23;
  margin-bottom: 4px;
}

.switch-row__desc {
  font-size: 12px;
  color: #86909c;
  line-height: 1.5;
}

/* 状态徽章 */
.status-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 12px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 500;
  align-self: flex-start;
}

.status-badge--on {
  background: #ffece8;
  color: #f53f3f;
}

.status-badge--off {
  background: #f2f3f5;
  color: #86909c;
}

.status-badge__dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: currentColor;
}

/* Alert 间距 */
.devmode-alert + .devmode-alert {
  margin-top: 12px;
}
</style>
