<script setup>
// "Lanjut dengan Google" + pemisah "atau". Disembunyikan bila Google OAuth belum dikonfigurasi.
import { computed } from 'vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import { config } from '@/config'

const props = defineProps({ redirect: { type: String, default: '/dashboard' }, label: { type: String, default: 'Lanjut dengan Google' } })
const url = computed(() => (config.googleEnabled ? `/auth/google/redirect?redirect=${encodeURIComponent(props.redirect)}` : null))
</script>

<template>
  <template v-if="url">
    <AppButton variant="secondary" block :href="url">
      <svg viewBox="0 0 24 24" class="size-5" aria-hidden="true"><path fill="currentColor" d="M21.35 11.1H12v2.98h5.38c-.23 1.4-1.66 4.1-5.38 4.1-3.24 0-5.88-2.68-5.88-6s2.64-6 5.88-6c1.84 0 3.08.79 3.79 1.46l2.58-2.49C16.71 3.6 14.57 2.6 12 2.6 6.82 2.6 2.62 6.8 2.62 12S6.82 21.4 12 21.4c5.43 0 9.03-3.82 9.03-9.2 0-.62-.07-1.09-.16-1.56z" /></svg>
      {{ label }}
    </AppButton>
    <div class="flex items-center gap-3"><hr class="flex-1 border-line" /><span class="text-sm text-muted">atau dengan email</span><hr class="flex-1 border-line" /></div>
  </template>
</template>
