<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { Message } from 'vue-devui'
import { profileApi } from '@/api/profile.api'

const router = useRouter()

const currentPassword = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const saving = ref(false)

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

const canSubmit = computed(
  () =>
    currentPassword.value !== '' &&
    newPassword.value.length >= 8 &&
    passwordCategories(newPassword.value) >= 3 &&
    confirmPassword.value === newPassword.value,
)

async function handleSubmit() {
  if (!canSubmit.value || saving.value) return
  saving.value = true
  try {
    await profileApi.changePassword(currentPassword.value, newPassword.value)
    Message.success('密码修改成功')
    router.push({ name: 'profile' })
  } catch {
    // Error toast is shown by the interceptor.
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="change-pwd-page">
    <h1 class="page-title">修改密码</h1>

    <div class="form-card">
      <label class="form-field">
        <span>当前密码</span>
        <d-input
          v-model="currentPassword"
          type="password"
          show-password
          placeholder="请输入当前密码"
        />
      </label>

      <label class="form-field">
        <span>新密码</span>
        <d-input
          v-model="newPassword"
          type="password"
          show-password
          placeholder="至少 8 位，含大小写/数字/符号中 3 类"
        />
        <small v-if="newPasswordError" class="field-error">{{ newPasswordError }}</small>
      </label>

      <label class="form-field">
        <span>确认新密码</span>
        <d-input
          v-model="confirmPassword"
          type="password"
          show-password
          placeholder="请再次输入新密码"
        />
        <small v-if="confirmError" class="field-error">{{ confirmError }}</small>
      </label>

      <div class="actions">
        <d-button type="common" @click="router.push({ name: 'profile' })">
          返回
        </d-button>
        <d-button
          type="primary"
          :loading="saving"
          :disabled="!canSubmit"
          @click="handleSubmit"
        >
          确认修改
        </d-button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.change-pwd-page {
  max-width: 480px;
}

.page-title {
  margin: 0 0 24px;
  font-size: 22px;
  font-weight: 600;
}

.form-card {
  background: #fff;
  border: 1px solid #e5e6eb;
  border-radius: 8px;
  padding: 28px;
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.form-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
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

.actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  margin-top: 8px;
}
</style>
