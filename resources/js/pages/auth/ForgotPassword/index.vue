<script setup>
// A-04 — lupa password: email (+ ALTCHA) → kode 6 digit → password baru → langsung masuk.
// Juga dipakai akun lama/akun Google untuk membuat password pertama kali. Sesi di perangkat lain dikeluarkan.
import { nextTick, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AltchaField from '@/components/auth/AltchaField/index.vue'
import AuthShell from '@/components/auth/AuthShell/index.vue'
import PasswordInput from '@/components/auth/PasswordInput/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppCheckbox from '@/components/ui/AppCheckbox/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import { authApi } from '@/api/auth'
import { config } from '@/config'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const step = ref('email') // email | reset
const form = reactive({ email: String(route.query.email || ''), altcha: null, code: '', password: '', password_confirmation: '', remember: true })
const errors = ref({})
const busy = ref(false)
const altchaRef = ref(null)

async function send() {
  errors.value = {}
  if (!/^\S+@\S+\.\S+$/.test(form.email)) return (errors.value = { email: ['Masukkan email yang valid.'] })
  if (!form.altcha) return (errors.value = { altcha: ['Tunggu verifikasi anti-robot selesai.'] })
  busy.value = true
  try {
    await authApi.forgotPassword({ email: form.email, altcha: form.altcha })
    step.value = 'reset'
    await nextTick()
    document.querySelector('input[autocomplete="one-time-code"]')?.focus()
  } catch (e) {
    errors.value = e.fields ?? { email: [e.message] }
  } finally {
    altchaRef.value?.reset()
    busy.value = false
  }
}

async function reset() {
  errors.value = {}
  const code = form.code.replace(/\D/g, '')
  if (code.length !== 6) return (errors.value = { code: ['Kode terdiri dari 6 angka.'] })
  if (form.password !== form.password_confirmation) return (errors.value = { password: ['Konfirmasi password tidak sama.'] })
  busy.value = true
  try {
    await authApi.resetPassword({ email: form.email, code, password: form.password, password_confirmation: form.password_confirmation, remember: form.remember })
    await auth.load(true)
    router.replace('/dashboard')
  } catch (e) {
    errors.value = e.fields ?? { code: [e.message] }
    busy.value = false
  }
}
</script>

<template>
  <AuthShell
    :title="step === 'email' ? 'Lupa password' : 'Buat password baru'"
    :subtitle="step === 'email' ? 'Masukkan email akun Anda. Kami kirim kode untuk membuat password baru.' : `Jika ${form.email} terdaftar, kode 6 digit sudah dikirim ke email itu. Berlaku 10 menit.`"
  >
    <form v-if="step === 'email'" class="grid gap-4" novalidate @submit.prevent="send">
      <AppField label="Email" :error="errors.email?.[0]">
        <AppInput v-model.trim="form.email" type="email" autocomplete="email" inputmode="email" placeholder="nama@email.com" autofocus :invalid="!!errors.email" />
      </AppField>
      <AltchaField ref="altchaRef" v-model="form.altcha" :error="errors.altcha?.[0]" />
      <AppButton type="submit" block :loading="busy">Kirim kode</AppButton>
    </form>

    <form v-else class="grid gap-4" novalidate @submit.prevent="reset">
      <p v-if="config.devLogin" class="text-sm text-warn">Mode lokal: gunakan kode {{ config.devLogin.code }}.</p>
      <AppField label="Kode dari email" :error="errors.code?.[0]">
        <AppInput v-model="form.code" code inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="••••••" :invalid="!!errors.code" />
      </AppField>
      <AppField label="Password baru" hint="Minimal 8 karakter, berisi huruf dan angka." :error="errors.password?.[0]">
        <PasswordInput v-model="form.password" autocomplete="new-password" :invalid="!!errors.password" />
      </AppField>
      <AppField label="Ulangi password baru">
        <PasswordInput v-model="form.password_confirmation" autocomplete="new-password" />
      </AppField>
      <AppCheckbox v-model="form.remember" label="Ingat saya 7 hari" />
      <AppButton type="submit" block :loading="busy">Simpan password & masuk</AppButton>
      <AppButton variant="ghost" @click="step = 'email'; errors = {}">Kirim ulang kode</AppButton>
    </form>

    <template #footer><RouterLink to="/login" class="font-semibold">Kembali ke halaman masuk</RouterLink></template>
  </AuthShell>
</template>
