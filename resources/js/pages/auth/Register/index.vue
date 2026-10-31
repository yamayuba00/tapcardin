<script setup>
// A-03 — daftar manual: nama, email, password (+ ALTCHA) → kode 6 digit ke email → akun dibuat & langsung masuk
// (workspace personal + kartu digital otomatis). Atau daftar sekali klik dengan Google.
import { computed, nextTick, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { MailCheck } from 'lucide-vue-next'
import AltchaField from '@/components/auth/AltchaField/index.vue'
import AuthShell from '@/components/auth/AuthShell/index.vue'
import GoogleButton from '@/components/auth/GoogleButton/index.vue'
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
const step = ref('form') // form | code
const form = reactive({ name: '', email: '', password: '', password_confirmation: '', altcha: null, code: '', remember: true })
const errors = ref({})
const busy = ref(false)
const altchaRef = ref(null)
const redirect = computed(() => {
  const r = String(route.query.redirect || '')
  return r.startsWith('/') && !r.startsWith('//') ? r : '/dashboard'
})
const strength = computed(() => {
  const p = form.password
  return [p.length >= 8, /[A-Za-z]/.test(p) && /\d/.test(p), p.length >= 12 || /[^A-Za-z0-9]/.test(p)].filter(Boolean).length
})

async function send() {
  errors.value = {}
  if (form.name.trim().length < 2) return (errors.value = { name: ['Isi nama Anda.'] })
  if (!/^\S+@\S+\.\S+$/.test(form.email)) return (errors.value = { email: ['Masukkan email yang valid.'] })
  if (form.password !== form.password_confirmation) return (errors.value = { password: ['Konfirmasi password tidak sama.'] })
  if (!form.altcha) return (errors.value = { altcha: ['Tunggu verifikasi anti-robot selesai.'] })
  busy.value = true
  try {
    await authApi.register({ name: form.name, email: form.email, password: form.password, password_confirmation: form.password_confirmation, altcha: form.altcha })
    step.value = 'code'
    await nextTick()
    document.querySelector('input[autocomplete="one-time-code"]')?.focus()
  } catch (e) {
    errors.value = e.fields ?? { email: [e.message] }
  } finally {
    altchaRef.value?.reset()
    busy.value = false
  }
}

async function verify() {
  errors.value = {}
  const code = form.code.replace(/\D/g, '')
  if (code.length !== 6) return (errors.value = { code: ['Kode terdiri dari 6 angka.'] })
  busy.value = true
  try {
    await authApi.verifyRegistration({ email: form.email, code, remember: form.remember })
    await auth.load(true)
    router.replace(redirect.value)
  } catch (e) {
    errors.value = e.fields ?? { code: [e.message] }
    busy.value = false
  }
}
function onCode(v) {
  form.code = String(v).replace(/\D/g, '').slice(0, 6)
  if (form.code.length === 6) verify()
}
</script>

<template>
  <AuthShell
    :title="step === 'form' ? 'Buat akun TapCard' : 'Cek email Anda'"
    :subtitle="step === 'form' ? 'Gratis. Kartu digital Anda langsung aktif setelah mendaftar.' : `Kami mengirim kode 6 digit ke ${form.email}. Berlaku 10 menit.`"
  >
    <template v-if="step === 'form'">
      <GoogleButton :redirect="redirect" label="Daftar dengan Google" />
      <form class="grid gap-4" novalidate @submit.prevent="send">
        <AppField label="Nama lengkap" :error="errors.name?.[0]">
          <AppInput v-model="form.name" autocomplete="name" maxlength="120" autofocus :invalid="!!errors.name" />
        </AppField>
        <AppField label="Email" :error="errors.email?.[0]">
          <AppInput v-model.trim="form.email" type="email" autocomplete="email" inputmode="email" placeholder="nama@email.com" :invalid="!!errors.email" />
        </AppField>
        <AppField label="Password" hint="Minimal 8 karakter, berisi huruf dan angka." :error="errors.password?.[0]">
          <PasswordInput v-model="form.password" autocomplete="new-password" :invalid="!!errors.password" />
        </AppField>
        <div v-if="form.password" class="flex items-center gap-2" aria-hidden="true">
          <span v-for="i in 3" :key="i" class="h-1 flex-1 rounded-full" :class="i <= strength ? (strength === 3 ? 'bg-ok' : 'bg-warn') : 'bg-surface-2'"></span>
          <span class="w-14 text-right text-xs text-muted">{{ ['Lemah', 'Lemah', 'Cukup', 'Kuat'][strength] }}</span>
        </div>
        <AppField label="Ulangi password">
          <PasswordInput v-model="form.password_confirmation" autocomplete="new-password" />
        </AppField>
        <AltchaField ref="altchaRef" v-model="form.altcha" :error="errors.altcha?.[0]" />
        <AppButton type="submit" block :loading="busy">Daftar & kirim kode</AppButton>
      </form>
    </template>

    <form v-else class="grid gap-4" novalidate @submit.prevent="verify">
      <p class="flex items-center gap-3 rounded-md bg-surface-2 px-3 py-3 text-sm"><MailCheck class="size-5 flex-none text-ok" /> Tidak ada di kotak masuk? Periksa folder spam / promosi.</p>
      <p v-if="config.devLogin" class="text-sm text-warn">Mode lokal: gunakan kode {{ config.devLogin.code }}.</p>
      <AppField label="Kode verifikasi" :error="errors.code?.[0] ?? errors.email?.[0]">
        <AppInput :model-value="form.code" code inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="••••••" :invalid="!!errors.code" @update:model-value="onCode" />
      </AppField>
      <AppCheckbox v-model="form.remember" label="Ingat saya 7 hari" />
      <AppButton type="submit" block :loading="busy">Verifikasi & masuk</AppButton>
      <AppButton variant="ghost" @click="step = 'form'; form.code = ''; errors = {}">Ubah data / kirim ulang kode</AppButton>
    </form>

    <template #footer>Sudah punya akun? <RouterLink :to="{ path: '/login', query: { redirect: route.query.redirect || undefined } }" class="font-semibold">Masuk</RouterLink></template>
  </AuthShell>
</template>
