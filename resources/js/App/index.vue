<script setup>
import LogoutConfirm from '@/components/common/LogoutConfirm/index.vue'
import ToastStack from '@/components/common/ToastStack/index.vue'
</script>

<template>
  <RouterView />
  <ToastStack />
  <LogoutConfirm />
</template>
