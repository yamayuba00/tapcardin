<script setup>
import ErrorScreen from '@/components/common/ErrorScreen/index.vue'
import LogoutConfirm from '@/components/common/LogoutConfirm/index.vue'
import ToastStack from '@/components/common/ToastStack/index.vue'
import { config } from '@/config'

// server mengirim shell dengan config.error untuk galat HTTP halaman (bukan API)
const error = config.error?.status ?? null
</script>

<template>
  <ErrorScreen v-if="error" :status="error" />
  <RouterView v-else />
  <ToastStack />
  <LogoutConfirm />
</template>
