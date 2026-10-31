<script setup>
// `/c/{token}` — URL permanen di chip & QR. Memanggil resolver API (tap dicatat di sana) lalu menampilkan
// profil (P-01), aktivasi (P-02), atau halaman netral (P-03) sesuai PRD §8.
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { Ban, Building2, CirclePause, Lock, MapPin, PackageOpen, SearchX, Sparkles, WifiOff } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import CenterMessage from '@/components/common/CenterMessage/index.vue'
import PublicProfileView from '@/components/profile/PublicProfileView/index.vue'
import { publicApi } from '@/api/public'
import { config, takeInitial } from '@/config'
import { setTemporaryTheme } from '@/composables/useTheme'

const route = useRoute()
const state = ref(null)
const failed = ref(false)
const activationCode = ref('')

/** A2 — kode dibawa lewat fragment (#code=…): tidak dikirim ke server/log, bertahan melewati login. */
function goActivate() {
  const code = activationCode.value.toUpperCase().replace(/[^0-9A-Z]/g, '').slice(0, 8)
  window.location.href = `${config.appUrl}/activate/${state.value.token}${code ? '#code=' + code : ''}`
}

// Halaman netral: tidak pernah menampilkan data pemilik — hanya status kartu & apa yang bisa dilakukan pengunjung.
const INACTIVE = {
  manufactured: { icon: PackageOpen, tone: 'info', badge: 'Dalam produksi', title: 'Kartu ini sedang disiapkan',
    text: 'Kartu baru saja dibuat dan belum sampai ke pemiliknya. Begitu diaktifkan, tap berikutnya langsung membuka profilnya.' },
  paused: { icon: CirclePause, tone: 'warn', badge: 'Dijeda', title: 'Profil sedang dijeda',
    text: 'Pemilik kartu menjeda profilnya untuk sementara. Coba lagi nanti, atau minta kontaknya secara langsung.' },
  lost: { icon: MapPin, tone: 'info', badge: 'Dilaporkan hilang', title: 'Terima kasih sudah menemukan kartu ini',
    text: 'Pemiliknya melaporkan kartu ini hilang, jadi data pribadinya disembunyikan. Pemilik sudah diberi tahu bahwa kartunya baru saja di-tap.' },
  revoked: { icon: Ban, tone: 'muted', badge: 'Tidak berlaku', title: 'Kartu ini sudah tidak berlaku',
    text: 'Kartu dinonaktifkan permanen — biasanya karena sudah diganti dengan kartu baru.' },
  suspended: { icon: Lock, tone: 'muted', badge: 'Tidak tersedia', title: 'Profil sementara tidak tersedia',
    text: 'Profil ini sedang tidak dapat ditampilkan. Silakan coba lagi nanti.' },
  company_idle: { icon: Building2, tone: 'muted', badge: 'Belum ditugaskan', title: 'Kartu ini belum dipakai', text: null },
}
const inactiveCopy = computed(() => {
  const c = INACTIVE[state.value?.reason] ?? INACTIVE.suspended
  return { ...c, text: c.text ?? `Kartu milik ${state.value?.brand?.name ?? 'perusahaan'} ini belum ditugaskan ke siapa pun. Hubungi perusahaan untuk informasi.` }
})

onMounted(async () => {
  try {
    // Kunjungan dari chip/QR: server sudah menyisipkan data & mencatat tap → tidak perlu panggilan API kedua.
    const initial = takeInitial('card')
    state.value = initial?.token === route.params.token ? initial : (await publicApi.resolve(route.params.token, route.query.src)).data
  } catch (e) {
    state.value = e.data?.data ?? { state: 'unknown' }
    failed.value = e.status !== 404
  }
  if (state.value?.state === 'profile') {
    const p = state.value.profile
    setTemporaryTheme(p.theme?.mode ?? 'dark')
    document.title = [p.display_name, [p.title, p.company].filter(Boolean).join(' · ')].filter(Boolean).join(' — ')
    document.documentElement.lang = p.locale || 'id'
  }
})
</script>

<template>
  <div v-if="!state" class="mx-auto grid max-w-[480px] justify-items-center gap-4 px-4 pt-12" aria-busy="true">
    <AppSkeleton class="!size-24 !rounded-full" />
    <AppSkeleton class="!h-10 w-3/4" />
    <AppSkeleton class="!h-14 w-full" />
  </div>

  <PublicProfileView v-else-if="state.state === 'profile'" :profile="state.profile" />

  <CenterMessage
    v-else-if="state.state === 'activate'" :icon="Sparkles" tone="ok" badge="Siap diaktifkan"
    title="Kartu baru Anda sudah tiba"
    text="Aktifkan sekali, lalu siapa pun yang Anda temui cukup menempelkan ponselnya untuk menyimpan kontak Anda."
  >
    <p class="label">Kode kartu <span class="font-mono text-lg tracking-[.2em] text-fg">{{ state.short_code }}</span></p>
    <form class="grid w-full gap-3 text-left" @submit.prevent="goActivate">
      <AppField label="Kode aktivasi (dari kemasan kartu)" hint="Kosongkan jika Anda pembeli kartu ini — cukup masuk dengan email pembelian.">
        <AppInput v-model="activationCode" code maxlength="9" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXX-XXXX" />
      </AppField>
      <AppButton type="submit" size="lg" block>Aktifkan kartu</AppButton>
    </form>
    <p class="text-sm text-muted">Berikutnya Anda masuk dengan email atau Google, lalu kartu langsung aktif.</p>
  </CenterMessage>

  <CenterMessage
    v-else-if="state.state === 'inactive'" promo
    :icon="inactiveCopy.icon" :tone="inactiveCopy.tone" :badge="inactiveCopy.badge" :title="inactiveCopy.title" :text="inactiveCopy.text"
    :logo="state.brand?.logo_url" :logo-alt="state.brand?.name"
  >
    <AppButton variant="secondary" to="/k">Punya kartu lain? Masukkan kode</AppButton>
  </CenterMessage>

  <CenterMessage
    v-else promo :icon="failed ? WifiOff : SearchX" :tone="failed ? 'danger' : 'muted'" :badge="failed ? 'Gangguan koneksi' : 'Tidak dikenal'"
    :title="failed ? 'Profil gagal dimuat' : 'Kartu tidak dikenali'"
    :text="failed ? 'Periksa koneksi internet lalu coba lagi.' : 'Tautan ini tidak terhubung ke kartu TapCard mana pun. Coba ketik kode pendek yang tercetak di kartu.'"
  >
    <AppButton v-if="failed" variant="secondary" @click="$router.go(0)">Coba lagi</AppButton>
    <AppButton v-else variant="secondary" to="/k">Masukkan kode kartu</AppButton>
  </CenterMessage>
</template>
