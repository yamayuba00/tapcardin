<script setup>
// A-01b — halaman khusus "Masuk dengan kartu NFC": pindai kartu (Chrome Android) → kode akun → masuk.
// Dibuka dari tombol di /login, atau dari tautan "Masuk sebagai pemilik" setelah kartu di-tap (membawa ?card=&k=).
// iPhone / komputer tidak bisa membaca NFC dari halaman web → tampilkan cara lewat tap kartu.
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft } from 'lucide-vue-next'
import AuthShell from '@/components/auth/AuthShell/index.vue'
import CardLogin from '@/components/auth/CardLogin/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const preset = /^[0-9A-Za-z]{10}$/.test(String(route.query.card || '')) && /^[0-9a-f]{12}$/.test(String(route.query.k || ''))
  ? { token: String(route.query.card), key: String(route.query.k) } : null
if (preset) router.replace({ query: { redirect: route.query.redirect || undefined } }) // kunci tidak tertinggal di riwayat

const redirect = computed(() => {
  const r = String(route.query.redirect || '')
  return r.startsWith('/') && !r.startsWith('//') ? r : '/dashboard'
})

async function done() {
  await auth.load(true)
  router.replace(redirect.value)
}
</script>

<template>
  <AuthShell title="Masuk dengan kartu NFC" subtitle="Tempelkan kartu TapCard Anda, lalu ketik kode akun 6 angka.">
    <CardLogin :preset="preset" @success="done" />
    <AppButton variant="ghost" :to="{ path: '/login', query: { redirect: route.query.redirect || undefined } }">
      <ArrowLeft class="size-5" /> Cara masuk lain
    </AppButton>
  </AuthShell>
</template>
