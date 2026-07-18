<script setup lang="ts">
</script>

<template>
  <div class="auth-layout">
    <div class="auth-logo">NYTab</div>
    <router-view />
  </div>
</template>

<style scoped>
.auth-layout {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background: #f7f8fa;
  padding: 24px;
}

.auth-logo {
  font-size: 28px;
  font-weight: 700;
  color: #1c1f23;
  margin-bottom: 32px;
}
</style>
