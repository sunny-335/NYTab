<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { Message } from 'vue-devui'
import { useAuthStore } from '@/stores/auth.store'
import { profileApi } from '@/api/profile.api'

const router = useRouter()
const authStore = useAuthStore()

const editingUsername = ref(authStore.user?.username || '')
const saving = ref(false)

const usernameChanged = computed(
  () => editingUsername.value.trim() !== authStore.user?.username,
)

const usernameValid = computed(() =>
  /^[a-zA-Z0-9_]{3,64}$/.test(editingUsername.value.trim()),
)

const canSave = computed(() => usernameChanged.value && usernameValid.value)

async function handleSave() {
  if (!canSave.value || saving.value) return
  saving.value = true
  try {
    await profileApi.update(editingUsername.value.trim())
    await authStore.fetchMe()
    Message.success('用户名已更新')
  } catch {
    // Error toast is shown by the interceptor.
  } finally {
    saving.value = false
  }
}

function goToChangePassword() {
  router.push({ name: 'change-password' })
}
</script>

<template>
  <div class="profile-page">
    <h1 class="page-title">个人中心</h1>

    <div class="profile-card">
      <h2 class="card-title">账号信息</h2>
      <div class="info-row">
        <span class="info-label">当前用户名</span>
        <span class="info-value">{{ authStore.user?.username || '—' }}</span>
      </div>

      <div class="edit-section">
        <label class="form-field">
          <span>修改用户名</span>
          <d-input v-model="editingUsername" placeholder="3-64 位字母、数字或下划线" />
        </label>
        <small v-if="editingUsername && !usernameValid" class="field-error">
          用户名需 3-64 位字母、数字或下划线
        </small>
        <d-button
          type="primary"
          :loading="saving"
          :disabled="!canSave"
          @click="handleSave"
        >
          保存
        </d-button>
      </div>
    </div>

    <div class="profile-card">
      <h2 class="card-title">安全设置</h2>
      <d-button type="common" @click="goToChangePassword">修改密码</d-button>
    </div>
  </div>
</template>

<style scoped>
.profile-page {
  max-width: 640px;
}

.page-title {
  margin: 0 0 24px;
  font-size: 22px;
  font-weight: 600;
}

.profile-card {
  background: #fff;
  border: 1px solid #e5e6eb;
  border-radius: 8px;
  padding: 24px;
  margin-bottom: 20px;
}

.card-title {
  margin: 0 0 16px;
  font-size: 16px;
  font-weight: 600;
}

.info-row {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 12px 0;
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

.edit-section {
  margin-top: 20px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  align-items: flex-start;
}

.form-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  width: 100%;
  max-width: 320px;
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
</style>
