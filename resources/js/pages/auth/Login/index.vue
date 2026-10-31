<script setup>
// A-01 — workspace (app.): email + password atau Google, ALTCHA anti-bot, "ingat saya" 7 hari.
// Panel pemilik (panel.): email + password khusus staf aktif; sesi terpisah & berakhir tiap 12 jam.
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AdminLoginForm from '@/components/auth/AdminLoginForm/index.vue'
import AltchaField from '@/components/auth/AltchaField/index.vue'
import AuthShell from '@/components/auth/AuthShell/index.vue'
import GoogleButton from '@/components/auth/GoogleButton/index.vue'
import PasswordInput from '@/components/auth/PasswordInput/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppCheckbox from '@/components/ui/AppCheckbox/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import { authApi } from '@/api/auth'
import { config, context } from '@/config'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const admin = context === 'admin'

const GOOGLE_ERRORS = { google: 'Login Google dibatalkan atau gagal. Coba lagi.', google_unverified: 'Email Google Anda belum terverifikasi.' }
const form = reactive({ email: String(route.query.email || ''), password: '', remember: true, altcha: null })
const errors = ref({})
const error = ref(GOOGLE_ERRORS[route.query.error] ?? '')
const busy = ref(false)
const altchaRef = ref(null)
const adminForm = ref(null)

const redirect = computed(() => {
  const r = String(route.query.redirect || '')
  return r.startsWith('/') && !r.startsWith('//') ? r : admin ? '/' : '/dashboard'
})
const keep = computed(() => ({ redirect: route.query.redirect || undefined }))

async function done() {
  await auth.load(true)
  router.replace(redirect.value)
}

async function submit() {
  errors.value = {}
  error.value = ''
  if (!/^\S+@\S+\.\S+$/.test(form.email)) return (errors.value = { email: ['Masukkan email yang valid.'] })
  if (!form.password) return (errors.value = { password: ['Masukkan password.'] })
  if (!form.altcha) return (errors.value = { altcha: ['Tunggu verifikasi anti-robot selesai (centang otomatis).'] })
  busy.value = true
  try {
    await authApi.login({ email: form.email, password: form.password, remember: form.remember, altcha: form.altcha })
    await done()
  } catch (e) {
    errors.value = e.fields ?? {}
    if (!e.fields) error.value = e.message
    altchaRef.value?.reset() // payload sekali pakai
    busy.value = false
  }
}

// Mode lokal saja (APP_ENV=local): akun dummy satu klik — isi email & password, lalu masuk setelah ALTCHA siap.
const devAccounts = computed(() => (config.devLogin?.accounts ?? []).filter((a) => a.staff === admin))
async function quickLogin(account) {
  if (admin) return adminForm.value?.fill(account.email, config.devLogin.password)
  form.email = account.email
  form.password = config.devLogin.password
  for (let i = 0; i < 50 && !form.altcha; i++) await new Promise((r) => setTimeout(r, 100))
  await submit()
}
</script>

<template>
  <div v-if="admin" class="h-1 bg-info"></div>
  <AuthShell
    :title="admin ? 'Masuk panel pemilik' : 'Masuk ke TapCard'"
    :subtitle="admin ? 'Khusus staf aktif. Sesi berakhir setiap 12 jam.' : 'Kelola kartu, bio link, dan koneksi Anda.'"
  >
    <span v-if="admin" class="label -mt-4 !text-info">Panel pemilik</span>

    <AdminLoginForm v-if="admin" ref="adminForm" @success="done" />

    <template v-else>
      <GoogleButton :redirect="redirect" />
      <form class="grid gap-4" novalidate @submit.prevent="submit">
        <p v-if="error" class="rounded-md border border-line-strong bg-surface-2 px-3 py-2 text-sm text-danger" role="alert">{{ error }}</p>
        <AppField label="Email" :error="errors.email?.[0]">
          <AppInput v-model.trim="form.email" type="email" autocomplete="username" inputmode="email" placeholder="nama@email.com" autofocus :invalid="!!errors.email" />
        </AppField>
        <AppField label="Password" :error="errors.password?.[0]">
          <PasswordInput v-model="form.password" autocomplete="current-password" :invalid="!!errors.password" />
        </AppField>
        <div class="flex items-center justify-between gap-3">
          <AppCheckbox v-model="form.remember" label="Ingat saya 7 hari" />
          <RouterLink :to="{ path: '/forgot-password', query: { email: form.email || undefined } }" class="text-sm">Lupa password?</RouterLink>
        </div>
        <AltchaField ref="altchaRef" v-model="form.altcha" :error="errors.altcha?.[0]" />
        <AppButton type="submit" block :loading="busy">Masuk</AppButton>
      </form>
    </template>

    <!-- Mode lokal: akun dummy satu klik -->
    <section v-if="config.devLogin && devAccounts.length" class="grid gap-3 rounded-md border border-dashed border-line-strong p-4">
      <p class="label !text-warn">Mode lokal · password {{ config.devLogin.password }}</p>
      <div class="grid gap-2">
        <button
          v-for="a in devAccounts" :key="a.email" type="button" :disabled="busy"
          class="cursor-pointer flex min-h-11 items-center justify-between gap-3 rounded-md border border-line px-3 text-left text-sm hover:bg-surface-2 disabled:opacity-50"
          @click="quickLogin(a)"
        >
          <span class="font-medium">{{ a.name }}</span><span class="font-mono text-xs text-muted">{{ a.email }}</span>
        </button>
      </div>
    </section>

    <template v-if="!admin" #footer>
      Belum punya akun? <RouterLink :to="{ path: '/register', query: keep }" class="font-semibold">Daftar gratis</RouterLink>
    </template>
  </AuthShell>
</template>
