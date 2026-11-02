<script setup>
// A-01 — workspace (app.): masuk dengan Google (akun baru dibuat otomatis) atau kartu NFC + PIN. Tanpa email + password.
// Panel pemilik (panel.): email + password khusus staf aktif; sesi terpisah & berakhir tiap 12 jam.
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AdminLoginForm from '@/components/auth/AdminLoginForm/index.vue'
import AuthShell from '@/components/auth/AuthShell/index.vue'
import CardLogin from '@/components/auth/CardLogin/index.vue'
import GoogleButton from '@/components/auth/GoogleButton/index.vue'
import { authApi } from '@/api/auth'
import { config, context } from '@/config'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const admin = context === 'admin'

const GOOGLE_ERRORS = { google: 'Login Google dibatalkan atau gagal. Coba lagi.', google_unverified: 'Email Google Anda belum terverifikasi.' }
const error = ref(GOOGLE_ERRORS[route.query.error] ?? '')
const busy = ref(false)
const adminForm = ref(null)

const redirect = computed(() => {
  const r = String(route.query.redirect || '')
  return r.startsWith('/') && !r.startsWith('//') ? r : admin ? '/' : '/dashboard'
})

async function done() {
  await auth.load(true)
  router.replace(redirect.value)
}

// Mode lokal saja (APP_ENV=local): akun dummy satu klik. Panel: isi email & password; app.: langsung masuk.
const devAccounts = computed(() => (config.devLogin?.accounts ?? []).filter((a) => a.staff === admin))
async function quickLogin(account) {
  if (admin) return adminForm.value?.fill(account.email, config.devLogin.password)
  error.value = ''
  busy.value = true
  try {
    await authApi.devLogin(account.email)
    await done()
  } catch (e) {
    error.value = e.message
    busy.value = false
  }
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

    <div v-else class="grid gap-4">
      <p v-if="error" class="rounded-md border border-line-strong bg-surface-2 px-3 py-2 text-sm text-danger" role="alert">{{ error }}</p>
      <GoogleButton :redirect="redirect" />
      <div class="flex items-center gap-3"><hr class="flex-1 border-line" /><span class="text-sm text-muted">atau</span><hr class="flex-1 border-line" /></div>
      <CardLogin @success="done" />
    </div>

    <!-- Mode lokal: akun dummy satu klik -->
    <section v-if="config.devLogin && devAccounts.length" class="grid gap-3 rounded-md border border-dashed border-line-strong p-4">
      <p class="label !text-warn">{{ admin ? `Mode lokal · password ${config.devLogin.password}` : 'Mode lokal · akun dummy' }}</p>
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
      Belum punya akun? Masuk dengan Google — akun dibuat otomatis.<br />
      Login kartu diaktifkan dari detail kartu fisik Anda setelah masuk.
    </template>
  </AuthShell>
</template>
