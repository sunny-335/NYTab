<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useSetupStore } from '@/stores/setup.store'
import type { DbConfig, AdminConfig } from '@/api/setup.api'

const router = useRouter()
const store = useSetupStore()

const currentStep = ref(1)

/* ----------------------------- Requirement labels ----------------------------- */
const REQUIREMENT_LABELS: Record<string, string> = {
  php_version: 'PHP 版本',
  pdo_pgsql: 'PDO PostgreSQL 扩展',
  json: 'JSON 扩展',
  writable_config: 'config 目录可写',
  writable_uploads: 'uploads 目录可写',
}

/* --------------------------------- Step 2: DB -------------------------------- */
const db = ref<DbConfig>({
  host: 'localhost',
  port: 5432,
  name: 'nytab',
  user: '',
  password: '',
})
const dbTested = ref(false)
const dbTesting = ref(false)

async function testDatabase() {
  dbTesting.value = true
  const ok = await store.testDatabase(db.value)
  dbTested.value = ok
  dbTesting.value = false
}

/** Reset the tested flag whenever the form changes so the user re-tests. */
function onDbFormChange() {
  dbTested.value = false
}

/** True when the tested target DB is missing and will be auto-created. */
const dbWillBeCreated = computed(
  () =>
    dbTested.value &&
    store.dbTestResult !== null &&
    !store.dbTestResult.databaseExists,
)

/** True when the target DB is missing AND the role lacks CREATEDB — install
 *  would fail, so warn the user up-front rather than mid-install. */
const dbCannotCreate = computed(
  () =>
    dbTested.value &&
    store.dbTestResult !== null &&
    !store.dbTestResult.databaseExists &&
    !store.dbTestResult.canCreate,
)

/* ----------------------------- Step 3: Admin ----------------------------- */
const admin = ref<AdminConfig>({
  username: '',
  password: '',
})
const confirmPassword = ref('')

function passwordCategories(pwd: string): number {
  let n = 0
  if (/[a-z]/.test(pwd)) n++
  if (/[A-Z]/.test(pwd)) n++
  if (/[0-9]/.test(pwd)) n++
  if (/[^a-zA-Z0-9]/.test(pwd)) n++
  return n
}

function isStrongPassword(pwd: string): boolean {
  return pwd.length >= 8 && passwordCategories(pwd) >= 3
}

function isValidUsername(name: string): boolean {
  return /^[a-zA-Z0-9_]{3,64}$/.test(name)
}

const usernameError = computed(() => {
  const u = admin.value.username
  if (!u) return ''
  if (!isValidUsername(u)) return '用户名需 3-64 位字母、数字或下划线'
  return ''
})

const passwordError = computed(() => {
  const p = admin.value.password
  if (!p) return ''
  if (p.length < 8) return '密码至少 8 位'
  if (passwordCategories(p) < 3) return '需包含大写/小写/数字/符号中至少 3 类'
  return ''
})

const confirmError = computed(() => {
  if (!confirmPassword.value) return ''
  if (confirmPassword.value !== admin.value.password) return '两次密码不一致'
  return ''
})

const adminValid = computed(
  () =>
    isValidUsername(admin.value.username) &&
    isStrongPassword(admin.value.password) &&
    confirmPassword.value === admin.value.password,
)

/* ----------------------------- Step 4: Install ----------------------------- */
const installing = ref(false)
const installSuccess = ref(false)

async function doInstall() {
  installing.value = true
  const ok = await store.install({
    database: { ...db.value },
    admin: { ...admin.value },
    corsOrigins: window.location.origin,
  })
  installing.value = false
  installSuccess.value = ok
}

function goToLogin() {
  router.push({ name: 'login' })
}

/* ----------------------------- Navigation ----------------------------- */
const canNext = computed(() => {
  switch (currentStep.value) {
    case 1:
      return store.requirementsOk
    case 2:
      return dbTested.value
    case 3:
      return adminValid.value
    default:
      return false
  }
})

function next() {
  if (canNext.value && currentStep.value < 4) {
    currentStep.value++
  }
}

function prev() {
  if (currentStep.value > 1) {
    currentStep.value--
  }
}

const steps = ['环境检测', '数据库配置', '管理员账号', '完成安装']

onMounted(() => {
  if (store.installed === null) {
    store.fetchStatus()
  }
})
</script>

<template>
  <div class="setup-wizard">
    <!-- Stepper -->
    <div class="stepper">
      <div
        v-for="(label, i) in steps"
        :key="i"
        class="step"
        :class="{
          active: currentStep === i + 1,
          done: currentStep > i + 1,
        }"
      >
        <span class="step-num">{{ i + 1 }}</span>
        <span class="step-label">{{ label }}</span>
      </div>
    </div>

    <div class="setup-card">
      <!-- Step 1: Environment -->
      <div v-if="currentStep === 1" class="step-content">
        <h2 class="step-title">环境检测</h2>
        <p v-if="store.loading" class="hint">正在检测环境…</p>
        <div v-else class="req-list">
          <div
            v-for="(item, key) in store.requirements"
            :key="key"
            class="req-item"
          >
            <span class="req-icon" :class="item.ok ? 'ok' : 'fail'">
              {{ item.ok ? '✓' : '✗' }}
            </span>
            <span class="req-name">{{ REQUIREMENT_LABELS[key] || key }}</span>
            <span class="req-detail">
              <template v-if="key === 'php_version'">
                需要 {{ item.required }}，当前 {{ item.actual }}
              </template>
              <template v-else-if="item.path">
                路径: {{ item.path }}
              </template>
            </span>
          </div>
        </div>
        <p v-if="!store.requirementsOk && !store.loading" class="warn">
          存在不满足项，请修复后再继续。
        </p>
      </div>

      <!-- Step 2: Database -->
      <div v-if="currentStep === 2" class="step-content">
        <h2 class="step-title">数据库配置</h2>
        <div class="form-grid">
          <label class="form-field">
            <span>主机地址</span>
            <d-input
              v-model="db.host"
              placeholder="localhost"
              @update:model-value="onDbFormChange"
            />
          </label>
          <label class="form-field">
            <span>端口</span>
            <d-input
              v-model="db.port"
              placeholder="5432"
              type="number"
              @update:model-value="onDbFormChange"
            />
          </label>
          <label class="form-field">
            <span>数据库名</span>
            <d-input
              v-model="db.name"
              placeholder="nytab"
              @update:model-value="onDbFormChange"
            />
          </label>
          <label class="form-field">
            <span>用户名</span>
            <d-input
              v-model="db.user"
              placeholder="数据库用户名"
              @update:model-value="onDbFormChange"
            />
          </label>
          <label class="form-field full">
            <span>密码</span>
            <d-input
              v-model="db.password"
              type="password"
              show-password
              placeholder="数据库密码"
              @update:model-value="onDbFormChange"
            />
          </label>
        </div>
        <div class="test-row">
          <d-button
            type="common"
            :loading="dbTesting"
            @click="testDatabase"
          >
            测试连接
          </d-button>
          <span v-if="dbTested" class="test-ok">✓ 连接成功</span>
          <span v-else-if="store.error" class="test-fail">
            ✗ {{ store.error }}
          </span>
        </div>
        <d-alert
          v-if="dbCannotCreate"
          type="warning"
          :closeable="false"
          class="db-alert"
        >
          数据库不存在,且当前用户没有创建数据库的权限,安装将失败
        </d-alert>
        <d-alert
          v-else-if="dbWillBeCreated"
          type="info"
          :closeable="false"
          class="db-alert"
        >
          数据库不存在,安装时将自动创建
        </d-alert>
      </div>

      <!-- Step 3: Admin -->
      <div v-if="currentStep === 3" class="step-content">
        <h2 class="step-title">管理员账号</h2>
        <div class="form-grid">
          <label class="form-field full">
            <span>用户名</span>
            <d-input
              v-model="admin.username"
              placeholder="3-64 位字母、数字或下划线"
            />
            <small v-if="usernameError" class="field-error">{{ usernameError }}</small>
          </label>
          <label class="form-field full">
            <span>密码</span>
            <d-input
              v-model="admin.password"
              type="password"
              show-password
              placeholder="至少 8 位，含大小写/数字/符号中 3 类"
            />
            <small v-if="passwordError" class="field-error">{{ passwordError }}</small>
          </label>
          <label class="form-field full">
            <span>确认密码</span>
            <d-input
              v-model="confirmPassword"
              type="password"
              show-password
              placeholder="再次输入密码"
            />
            <small v-if="confirmError" class="field-error">{{ confirmError }}</small>
          </label>
        </div>
      </div>

      <!-- Step 4: Complete -->
      <div v-if="currentStep === 4" class="step-content">
        <h2 class="step-title">完成安装</h2>
        <div v-if="!installSuccess" class="install-pending">
          <p class="hint">点击下方按钮执行安装。安装将创建数据库表与管理员账号。</p>
          <d-button
            type="primary"
            :loading="installing"
            @click="doInstall"
          >
            开始安装
          </d-button>
          <p v-if="store.error" class="test-fail">{{ store.error }}</p>
        </div>
        <div v-else class="install-done">
          <div class="success-icon">✓</div>
          <p class="success-text">安装完成！</p>
          <d-button type="primary" @click="goToLogin">进入登录</d-button>
        </div>
      </div>

      <!-- Nav buttons -->
      <div class="nav-buttons" v-if="!installSuccess">
        <d-button
          v-if="currentStep > 1 && currentStep < 4"
          type="common"
          @click="prev"
        >
          上一步
        </d-button>
        <d-button
          v-if="currentStep < 3"
          type="primary"
          :disabled="!canNext"
          @click="next"
        >
          下一步
        </d-button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.setup-wizard {
  width: 100%;
  max-width: 680px;
}

.stepper {
  display: flex;
  justify-content: space-between;
  margin-bottom: 32px;
}

.step {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  flex: 1;
  position: relative;
}

.step:not(:last-child)::after {
  content: '';
  position: absolute;
  top: 14px;
  left: 50%;
  right: -50%;
  height: 2px;
  background: #e5e6eb;
  z-index: 0;
}

.step.done:not(:last-child)::after {
  background: #1668dc;
}

.step-num {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: #e5e6eb;
  color: #86909c;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  font-weight: 600;
  z-index: 1;
}

.step.active .step-num {
  background: #1668dc;
  color: #fff;
}

.step.done .step-num {
  background: #1668dc;
  color: #fff;
}

.step-label {
  font-size: 12px;
  color: #86909c;
}

.step.active .step-label {
  color: #1668dc;
  font-weight: 600;
}

.setup-card {
  background: #fff;
  border: 1px solid #e5e6eb;
  border-radius: 8px;
  padding: 32px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}

.step-title {
  margin: 0 0 24px;
  font-size: 18px;
  font-weight: 600;
}

.req-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.req-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  background: #f7f8fa;
  border-radius: 6px;
}

.req-icon {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  color: #fff;
  flex-shrink: 0;
}

.req-icon.ok {
  background: #00b42a;
}

.req-icon.fail {
  background: #f53f3f;
}

.req-name {
  font-size: 14px;
  font-weight: 500;
  min-width: 140px;
}

.req-detail {
  font-size: 13px;
  color: #86909c;
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}

.form-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.form-field.full {
  grid-column: 1 / -1;
}

.form-field > span {
  font-size: 13px;
  color: #4e5969;
  font-weight: 500;
}

.field-error {
  color: #f53f3f;
  font-size: 12px;
}

.test-row {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-top: 16px;
}

.db-alert {
  margin-top: 12px;
}

.test-ok {
  color: #00b42a;
  font-size: 14px;
}

.test-fail {
  color: #f53f3f;
  font-size: 14px;
}

.hint {
  color: #86909c;
  font-size: 14px;
}

.warn {
  color: #ff7d00;
  font-size: 14px;
  margin-top: 16px;
}

.install-pending {
  display: flex;
  flex-direction: column;
  gap: 16px;
  align-items: flex-start;
}

.install-done {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
}

.success-icon {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: #00b42a;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28px;
}

.success-text {
  font-size: 18px;
  font-weight: 600;
  margin: 0;
}

.nav-buttons {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  margin-top: 32px;
  padding-top: 24px;
  border-top: 1px solid #f2f3f5;
}
</style>
