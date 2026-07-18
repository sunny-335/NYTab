<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth.store'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const username = ref('')
const password = ref('')
const loading = ref(false)

const canSubmit = computed(
  () => username.value.trim() !== '' && password.value !== '',
)

async function handleSubmit() {
  if (!canSubmit.value || loading.value) return
  loading.value = true
  try {
    await authStore.login(username.value.trim(), password.value)
    const redirect = route.query.redirect
    router.push(typeof redirect === 'string' ? redirect : '/')
  } catch {
    // Error toast is already shown by the axios interceptor.
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-card">
    <h1 class="login-title">登录</h1>
    <form class="login-form" @submit.prevent="handleSubmit">
      <label class="form-field">
        <span>用户名</span>
        <d-input
          v-model="username"
          placeholder="请输入用户名"
          :autofocus="true"
        />
      </label>
      <label class="form-field">
        <span>密码</span>
        <d-input
          v-model="password"
          type="password"
          show-password
          placeholder="请输入密码"
          @keydown.enter="handleSubmit"
        />
      </label>
      <d-button
        type="primary"
        :loading="loading"
        :disabled="!canSubmit"
        class="submit-btn"
        @click="handleSubmit"
      >
        登录
      </d-button>
    </form>
  </div>
</template>

<style scoped>
.login-card {
  width: 100%;
  max-width: 380px;
  background: #fff;
  border: 1px solid #e5e6eb;
  border-radius: 8px;
  padding: 40px 32px;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
}

.login-title {
  margin: 0 0 28px;
  font-size: 22px;
  font-weight: 600;
  text-align: center;
}

.login-form {
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

.submit-btn {
  width: 100%;
  margin-top: 8px;
}
</style>
