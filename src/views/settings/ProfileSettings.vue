<script setup lang="ts">
import { ref, computed } from 'vue'
import { Message } from 'vue-devui'
import { useAuthStore } from '@/stores/auth.store'
import { profileApi } from '@/api/profile.api'

/**
 * ProfileSettings — 个人中心子页(用户名 + 密码修改)。
 *
 * 用户名修改:复用 authStore.user 作为初始值,PUT /profile 后调
 *   authStore.fetchMe() 同步本地状态。
 * 密码修改:内联展示三个字段(当前 / 新 / 确认),前端先校验强度与
 *   一致性,通过后调 PUT /profile/password。修改成功后清空表单。
 */
const authStore = useAuthStore()

/* --------------------------- 用户名修改 --------------------------- */
const editingUsername = ref(authStore.user?.username || '')
const savingUsername = ref(false)

const usernameChanged = computed(
  () => editingUsername.value.trim() !== (authStore.user?.username || ''),
)

const usernameValid = computed(() =>
  /^[a-zA-Z0-9_]{3,64}$/.test(editingUsername.value.trim()),
)

const canSaveUsername = computed(
  () => usernameChanged.value && usernameValid.value && !savingUsername.value,
)

async function handleSaveUsername(): Promise<void> {
  if (!canSaveUsername.value) return
  savingUsername.value = true
  try {
    await profileApi.update(editingUsername.value.trim())
    await authStore.fetchMe()
    Message.success('用户名已更新')
  } catch {
    // 拦截器已 toast
  } finally {
    savingUsername.value = false
  }
}

/* --------------------------- 密码修改 --------------------------- */
const currentPassword = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const savingPassword = ref(false)

function passwordCategories(pwd: string): number {
  let n = 0
  if (/[a-z]/.test(pwd)) n++
  if (/[A-Z]/.test(pwd)) n++
  if (/[0-9]/.test(pwd)) n++
  if (/[^a-zA-Z0-9]/.test(pwd)) n++
  return n
}

const newPasswordError = computed(() => {
  const p = newPassword.value
  if (!p) return ''
  if (p.length < 8) return '密码至少 8 位'
  if (passwordCategories(p) < 3) return '需包含大写/小写/数字/符号中至少 3 类'
  return ''
})

const confirmError = computed(() => {
  if (!confirmPassword.value) return ''
  if (confirmPassword.value !== newPassword.value) return '两次密码不一致'
  return ''
})

const canSubmitPassword = computed(
  () =>
    !savingPassword.value &&
    currentPassword.value !== '' &&
    newPassword.value.length >= 8 &&
    passwordCategories(newPassword.value) >= 3 &&
    confirmPassword.value === newPassword.value,
)

async function handleChangePassword(): Promise<void> {
  if (!canSubmitPassword.value) return
  savingPassword.value = true
  try {
    await profileApi.changePassword(
      currentPassword.value,
      newPassword.value,
    )
    Message.success('密码修改成功')
    currentPassword.value = ''
    newPassword.value = ''
    confirmPassword.value = ''
  } catch {
    // 拦截器已 toast
  } finally {
    savingPassword.value = false
  }
}
</script>

<template>
  <div class="profile-settings">
    <h1 class="page-title">个人中心</h1>

    <!-- 账号信息 -->
    <section class="settings-card">
      <h2 class="card-title">账号信息</h2>
      <div class="info-row">
        <span class="info-label">当前用户名</span>
        <span class="info-value">{{ authStore.user?.username || '—' }}</span>
      </div>

      <label class="form-field">
        <span class="form-label">修改用户名</span>
        <d-input
          v-model="editingUsername"
          placeholder="3-64 位字母、数字或下划线"
        />
        <small v-if="editingUsername && !usernameValid" class="field-error">
          用户名需 3-64 位字母、数字或下划线
        </small>
      </label>

      <div class="card-actions">
        <d-button
          type="primary"
          :loading="savingUsername"
          :disabled="!canSaveUsername"
          @click="handleSaveUsername"
        >
          保存用户名
        </d-button>
      </div>
    </section>

    <!-- 安全设置 -->
    <section class="settings-card">
      <h2 class="card-title">安全设置</h2>

      <label class="form-field">
        <span class="form-label">当前密码</span>
        <d-input
          v-model="currentPassword"
          type="password"
          show-password
          placeholder="请输入当前密码"
        />
      </label>

      <label class="form-field">
        <span class="form-label">新密码</span>
        <d-input
          v-model="newPassword"
          type="password"
          show-password
          placeholder="至少 8 位，含大小写/数字/符号中 3 类"
        />
        <small v-if="newPasswordError" class="field-error">
          {{ newPasswordError }}
        </small>
      </label>

      <label class="form-field">
        <span class="form-label">确认新密码</span>
        <d-input
          v-model="confirmPassword"
          type="password"
          show-password
          placeholder="请再次输入新密码"
        />
        <small v-if="confirmError" class="field-error">{{ confirmError }}</small>
      </label>

      <div class="card-actions">
        <d-button
          type="primary"
          :loading="savingPassword"
          :disabled="!canSubmitPassword"
          @click="handleChangePassword"
        >
          修改密码
        </d-button>
      </div>
    </section>
  </div>
</template>

<style scoped>
.profile-settings {
  max-width: 640px;
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
  gap: 14px;
}

.card-title {
  margin: 0 0 4px;
  font-size: 16px;
  font-weight: 600;
  color: #1c1f23;
}

.info-row {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 10px 0;
  border-bottom: 1px solid #f2f3f5;
}

.info-label {
  font-size: 14px;
  color: #86909c;
  min-width: 120px;
}

.info-value {
  font-size: 14px;
  color: #1c1f23;
  font-weight: 500;
}

.form-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  width: 100%;
  max-width: 360px;
}

.form-label {
  font-size: 13px;
  color: #4e5969;
  font-weight: 500;
}

.field-error {
  color: #f53f3f;
  font-size: 12px;
}

.card-actions {
  margin-top: 4px;
  display: flex;
  justify-content: flex-start;
}
</style>
