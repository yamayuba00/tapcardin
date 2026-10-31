<script setup>
// A-01 / A-02 — workspace (app.): email OTP 6 digit atau Google (AFD F-01).
// Panel pemilik (admin.): email + password, hanya staf aktif; sesi terpisah & berakhir tiap 12 jam.
import { computed, nextTick, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AdminLoginForm from '@/components/auth/AdminLoginForm/index.vue'
import BrandLogo from '@/components/common/BrandLogo/index.vue'
import { authApi } from '@/api/auth'
import { config, context } from '@/config'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const admin = context === 'admin'

const step = ref('email') // email | code (workspace saja)
const email = ref('')
const code = ref('')
const busy = ref(false)
const info = ref('')
const error = ref(route.query.error ? 'Login Google dibatalkan atau gagal. Coba lagi.' : '')
const adminForm = ref(null)

const redirect = computed(() => {
  const r = String(route.query.redirect || '')
  return r.startsWith('/') && !r.startsWith('//') ? r : admin ? '/' : '/dashboard'
})
const googleUrl = computed(() => (!admin && config.googleEnabled ? `/auth/google/redirect?redirect=${encodeURIComponent(redirect.value)}` : null))

async function done() {
  await auth.load(true)
  router.replace(redirect.value)
}

async function requestCode() {
  error.value = ''
  if (!/^\S+@\S+\.\S+$/.test(email.value)) return (error.value = 'Masukkan email yang valid.')
  busy.value = true
  try {
    await authApi.requestCode(email.value)
    step.value = 'code'
    info.value = `Kode 6 digit dikirim ke ${email.value}. Berlaku 10 menit.`
    await nextTick()
    document.querySelector('input[autocomplete="one-time-code"]')?.focus()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function verify() {
  error.value = ''
  const c = code.value.replace(/\D/g, '')
  if (c.length !== 6) return (error.value = 'Kode terdiri dari 6 angka.')
  busy.value = true
  try {
    await authApi.verify(email.value, c)
    await done()
  } catch (e) {
    error.value = e.message
    if (/minta kode baru/i.test(e.message)) code.value = ''
    busy.value = false
  }
}

function onCode(v) {
  code.value = String(v).replace(/\D/g, '').slice(0, 6)
  if (code.value.length === 6) verify()
}

function resetEmail() {
  step.value = 'email'
  code.value = ''
  error.value = ''
}

// Mode lokal saja (APP_ENV=local + DEV_LOGIN_CODE): login satu klik dengan akun dummy.
const devAccounts = computed(() => (config.devLogin?.accounts ?? []).filter((a) => a.staff === admin))
async function quickLogin(account) {
  if (admin) return adminForm.value?.fill(account.email, config.devLogin.staff_password)
  email.value = account.email
  await requestCode()
  if (step.value !== 'code') return
  code.value = config.devLogin.code
  await verify()
}
</script>

<template>
  <div v-if="admin" class="h-1 bg-info"></div>
  <main class="grid min-h-screen place-items-center px-4 py-6">
    <div class="grid w-full max-w-[400px] gap-6">
      <div class="grid gap-2">
        <BrandLogo />
        <span v-if="admin" class="label !text-info">Panel pemilik</span>
      </div>
      <div class="grid gap-2">
        <h1>{{ admin ? 'Masuk panel pemilik' : 'Masuk ke TapCard' }}</h1>
        <p class="text-muted">{{ admin ? 'Khusus staf aktif. Sesi berakhir setiap 12 jam.' : 'Tanpa password. Kami kirim kode ke email Anda.' }}</p>
      </div>

      <!-- Panel pemilik: email + password -->
      <AdminLoginForm v-if="admin" ref="adminForm" @success="done" />

      <!-- Workspace langkah 1: email -->
      <form v-else-if="step === 'email'" class="grid gap-4" novalidate @submit.prevent="requestCode">
        <AppField label="Email" :error="error">
          <AppInput v-model.trim="email" type="email" autocomplete="email" inputmode="email" placeholder="nama@email.com" autofocus :invalid="!!error" />
        </AppField>
        <AppButton type="submit" block :loading="busy">Kirim kode</AppButton>
        <template v-if="googleUrl">
          <div class="flex items-center gap-3"><hr class="flex-1 border-line" /><span class="text-sm text-muted">atau</span><hr class="flex-1 border-line" /></div>
          <AppButton variant="secondary" block :href="googleUrl">Lanjut dengan Google</AppButton>
        </template>
      </form>

      <!-- Workspace langkah 2: kode OTP -->
      <form v-else class="grid gap-4" novalidate @submit.prevent="verify">
        <p class="text-sm">{{ info }}</p>
        <p v-if="config.devLogin" class="text-sm text-warn">Mode lokal: gunakan kode {{ config.devLogin.code }}.</p>
        <AppField label="Kode masuk" :error="error">
          <AppInput :model-value="code" code inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="••••••" :invalid="!!error" @update:model-value="onCode" />
        </AppField>
        <AppButton type="submit" block :loading="busy">Masuk</AppButton>
        <div class="flex justify-between">
          <AppButton variant="ghost" @click="resetEmail">Ganti email</AppButton>
          <AppButton variant="ghost" :disabled="busy" @click="requestCode">Kirim ulang kode</AppButton>
        </div>
      </form>

      <!-- Mode lokal: akun dummy satu klik -->
      <section v-if="config.devLogin && devAccounts.length && (admin || step === 'email')" class="grid gap-3 rounded-md border border-dashed border-line-strong p-4">
        <p class="label !text-warn">
          Mode lokal · {{ admin ? `password ${config.devLogin.staff_password}` : `kode login ${config.devLogin.code}` }}
        </p>
        <p class="text-sm text-muted">Klik akun dummy untuk langsung masuk.</p>
        <div class="grid gap-2">
          <button
            v-for="a in devAccounts"
            :key="a.email"
            type="button"
            :disabled="busy"
            class="cursor-pointer flex min-h-11 items-center justify-between gap-3 rounded-md border border-line px-3 text-left text-sm hover:bg-surface-2 disabled:opacity-50"
            @click="quickLogin(a)"
          >
            <span class="font-medium">{{ a.name }}</span><span class="font-mono text-xs text-muted">{{ a.email }}</span>
          </button>
        </div>
      </section>
    </div>
  </main>
</template>
