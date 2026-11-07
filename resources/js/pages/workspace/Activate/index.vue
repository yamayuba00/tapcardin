<script setup>
// AFD F-05 / P-02 — pembeli aktif tanpa kode; selain itu kode aktivasi 8 karakter, terkunci 1 jam setelah 5 kali salah.
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CircleAlert, Lock } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import TapZone from '@/components/common/TapZone/index.vue'
import { cardsApi } from '@/api/workspace'
import { useAsync } from '@/composables/useAsync'
import { dateTime } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const code = ref('')
// A2 — kode yang sudah diisi di halaman tap. ikut lewat #code=…; begitu login, kartu langsung diaktifkan.
const carried = new URLSearchParams(route.hash.slice(1)).get('code')
const { data: card, error: loadError } = useAsync(async () => {
  const res = await cardsApi.activation(route.params.token)
  const c = res.data
  if (c.status === 'unclaimed' && !c.already_mine && !c.locked_until && (carried?.length === 8 || c.is_buyer)) {
    if (carried) code.value = `${carried.slice(0, 4)}-${carried.slice(4)}`
    queueMicrotask(() => activate(c))
  }
  if (carried) router.replace({ hash: '' }) // jangan biarkan kode tertinggal di riwayat browser
  return res
})

const busy = ref(false)
const done = ref(false)
const error = ref(null)

const formatted = computed({
  get: () => code.value,
  set: (v) => {
    const s = String(v).toUpperCase().replace(/[^0-9A-Z]/g, '').slice(0, 8)
    code.value = s.length > 4 ? `${s.slice(0, 4)}-${s.slice(4)}` : s
  },
})
const ready = computed(() => card.value?.is_buyer || code.value.replace('-', '').length === 8)

async function activate(c = card.value) {
  error.value = null
  busy.value = true
  try {
    const { data } = await cardsApi.activate(c.token, c.is_buyer ? null : code.value)
    done.value = true
    setTimeout(() => router.push(data.next), 900)
  } catch (e) {
    error.value = e
    busy.value = false
  }
}
</script>

<template>
  <div class="mx-auto grid w-full max-w-[400px] gap-6">
    <TapZone :state="done ? 'ok' : busy ? 'waiting' : 'idle'" />

    <p v-if="loadError" class="text-center text-danger">Kartu tidak ditemukan.</p>

    <template v-else-if="card">
      <div v-if="card.already_mine" class="grid gap-4">
        <h1>Kartu ini sudah aktif</h1>
        <p class="text-muted">Kartu {{ card.short_code }} sudah terhubung ke akun Anda.</p>
        <AppButton :to="`/cards/${card.token}/setup`">Atur kartu</AppButton>
      </div>

      <div v-else-if="card.status !== 'unclaimed'" class="grid gap-4">
        <h1>Kartu tidak bisa diaktifkan</h1>
        <p class="text-muted">Kartu {{ card.short_code }} sudah dipakai atau tidak berlaku. Hubungi support jika ini kartu Anda.</p>
        <AppButton variant="secondary" to="/dashboard">Ke beranda</AppButton>
      </div>

      <div v-else-if="done" class="grid gap-2"><h1>Kartu aktif!</h1><p class="text-muted">Menyiapkan profil Anda…</p></div>

      <form v-else class="grid gap-4" novalidate @submit.prevent="activate()">
        <div class="grid gap-2">
          <span class="label">Kartu {{ card.short_code }}</span>
          <h1>Aktifkan kartu</h1>
          <p class="text-muted">Masuk sebagai <strong class="text-fg">{{ card.email }}</strong>.</p>
        </div>
        <p v-if="card.locked_until" class="flex items-center gap-2 rounded-md border border-line-strong bg-surface-2 px-4 py-3 text-sm">
          <Lock class="size-5 text-warn" /> Terlalu banyak percobaan. Coba lagi setelah {{ dateTime(card.locked_until) }}.
        </p>
        <p v-if="card.is_buyer">Anda pembeli kartu ini, jadi tidak perlu kode aktivasi.</p>
        <AppField v-else label="Kode aktivasi" hint="8 karakter, tercetak di kartu kecil yang dikirim bersama kartu.">
          <AppInput v-model="formatted" code maxlength="9" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXX-XXXX" :invalid="!!error" />
        </AppField>
        <p v-if="error" class="flex items-center gap-1 text-sm text-danger" role="alert"><CircleAlert class="size-4" /> {{ error.message }}</p>
        <AppButton type="submit" size="lg" block :loading="busy" :disabled="!!card.locked_until || !ready">Aktifkan kartu</AppButton>
        <AppButton v-if="error?.code === 'card_assigned_elsewhere'" variant="secondary" block @click="auth.askLogout()">Keluar & masuk dengan email lain</AppButton>
      </form>
    </template>
  </div>
</template>
