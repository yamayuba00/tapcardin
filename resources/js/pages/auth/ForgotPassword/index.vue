<script setup>
// Panel pemilik — lupa password: kirim tautan reset ke email staf. Jawaban selalu sama (tidak membocorkan siapa staf).
import { ref } from 'vue'
import { ArrowLeft, MailCheck } from 'lucide-vue-next'
import AuthShell from '@/components/auth/AuthShell/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import { authApi } from '@/api/auth'

const email = ref('')
const busy = ref(false)
const error = ref('')
const sent = ref('')

async function submit() {
  error.value = ''
  if (!email.value) return (error.value = 'Isi email akun panel Anda.')
  busy.value = true
  try {
    sent.value = (await authApi.forgotPassword(email.value)).data.message
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="h-1 bg-info"></div>
  <AuthShell title="Lupa password" subtitle="Kami kirim tautan untuk membuat password baru ke email staf Anda.">
    <div v-if="sent" class="grid gap-4">
      <p class="flex gap-3 rounded-md border border-line bg-surface p-4 text-sm" role="status">
        <MailCheck class="size-5 shrink-0 text-ok" /> <span>{{ sent }} Tautan berlaku 60 menit.</span>
      </p>
      <AppButton variant="secondary" block @click="sent = ''">Kirim ulang</AppButton>
    </div>
    <form v-else class="grid gap-4" novalidate @submit.prevent="submit">
      <AppField label="Email" :error="error">
        <AppInput v-model.trim="email" type="email" autocomplete="username" autofocus :invalid="!!error" />
      </AppField>
      <AppButton type="submit" block :loading="busy">Kirim tautan reset</AppButton>
    </form>
    <template #footer>
      <RouterLink to="/login" class="inline-flex min-h-11 items-center gap-2 font-semibold text-accent-text underline"><ArrowLeft class="size-4" /> Kembali ke halaman masuk</RouterLink>
    </template>
  </AuthShell>
</template>
