<script setup>
// Panel pemilik — buat password baru dari tautan email (?token=&email=). Berhasil → semua sesi lama berakhir, masuk ulang.
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AuthShell from '@/components/auth/AuthShell/index.vue'
import PasswordInput from '@/components/auth/PasswordInput/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import { authApi } from '@/api/auth'
import { useToastStore } from '@/stores/toast'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()
const token = String(route.query.token || '')
const email = String(route.query.email || '')
const form = reactive({ password: '', password_confirmation: '' })
const errors = ref({})
const busy = ref(false)

async function submit() {
  errors.value = {}
  busy.value = true
  try {
    const res = await authApi.resetPassword({ token, email, ...form })
    toast.ok(res.data.message)
    router.replace('/login')
  } catch (e) {
    errors.value = e.fields || { token: [e.message] }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="h-1 bg-info"></div>
  <AuthShell title="Buat password baru" :subtitle="email ? `Untuk ${email}` : undefined">
    <div v-if="!token || !email" class="grid gap-4">
      <p class="rounded-md border border-line-strong bg-surface-2 px-3 py-2 text-sm text-danger" role="alert">Tautan tidak lengkap. Buka lagi tautan dari email, atau minta tautan baru.</p>
      <AppButton block to="/forgot-password">Minta tautan baru</AppButton>
    </div>
    <form v-else class="grid gap-4" novalidate @submit.prevent="submit">
      <p v-if="errors.token" class="rounded-md border border-line-strong bg-surface-2 px-3 py-2 text-sm text-danger" role="alert">
        {{ errors.token[0] }} <RouterLink to="/forgot-password" class="font-semibold underline">Minta tautan baru</RouterLink>
      </p>
      <AppField label="Password baru" hint="Minimal 8 karakter, berisi huruf dan angka." :error="errors.password?.[0]">
        <PasswordInput v-model="form.password" autocomplete="new-password" :invalid="!!errors.password" autofocus />
      </AppField>
      <AppField label="Ulangi password baru">
        <PasswordInput v-model="form.password_confirmation" autocomplete="new-password" />
      </AppField>
      <AppButton type="submit" block :loading="busy">Simpan password</AppButton>
    </form>
  </AuthShell>
</template>
