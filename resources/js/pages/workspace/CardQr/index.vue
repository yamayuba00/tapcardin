<script setup>
// U-11 / AFD F-11 — QR aman layar penuh: token sekali pakai 60 detik, hitung mundur, diperbarui otomatis
// 5 detik sebelum habis. Setelah di-scan, QR itu tidak bisa dipakai ulang (mis. bila difoto orang lain).
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowLeft, Lock, RefreshCw } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import UpgradePrompt from '@/components/billing/UpgradePrompt/index.vue'
import { cardsApi } from '@/api/workspace'

const route = useRoute()
const token = route.params.token
const qr = ref(null)
const error = ref(null)
const left = ref(0)
let tick = null
let wake = null

async function issue() {
  try {
    qr.value = (await cardsApi.dynamicQr(token)).data
    error.value = null
  } catch (e) {
    error.value = e
    clearInterval(tick)
  }
}
onMounted(async () => {
  await issue()
  tick = setInterval(() => {
    if (!qr.value) return
    left.value = Math.max(0, Math.round((Date.parse(qr.value.expires_at) - Date.now()) / 1000))
    if (left.value <= 5) issue()
  }, 500)
  // layar tetap menyala selama QR ditampilkan (bila didukung)
  wake = await navigator.wakeLock?.request('screen').catch(() => null)
})
onBeforeUnmount(() => {
  clearInterval(tick)
  wake?.release?.()
})
const pct = computed(() => (qr.value ? (left.value / qr.value.ttl) * 100 : 0))
</script>

<template>
  <div :class="['mx-auto grid w-full', error?.code === 'feature_locked' ? 'max-w-[560px]' : 'max-w-[440px]']" class=" justify-items-center gap-6 py-4 text-center">
    <div class="flex w-full items-center justify-between">
      <AppButton variant="ghost" :to="`/cards/${token}`"><ArrowLeft class="size-5" /> Kartu</AppButton>
      <span class="label inline-flex items-center gap-1"><Lock class="size-4" /> QR aman</span>
    </div>

    <UpgradePrompt
      v-if="error?.code === 'feature_locked'"
      title="QR aman sekali pakai"
      text="Tampilkan QR di layar yang berganti tiap 60 detik dan hanya bisa di-scan sekali — cocok saat kartu tertinggal atau QR cetak tidak ingin difoto orang."
      :benefits="['QR baru otomatis tiap 60 detik, layar tetap menyala', 'Setiap QR hanya bisa dipakai satu kali', 'Tap tercatat sebagai QR aman di analitik', 'Plus 10 kartu aktif & koneksi tanpa batas']"
    />
    <p v-else-if="error" class="text-danger" role="alert">{{ error.message }}</p>

    <template v-else-if="qr">
      <!-- QR selalu gelap di atas terang agar terbaca semua kamera, apa pun tema aplikasi -->
      <div class="w-full rounded-lg bg-bone-0 p-5" data-theme="light">
        <svg :viewBox="`0 0 ${qr.qr.modules} ${qr.qr.modules}`" class="block aspect-square w-full" shape-rendering="crispEdges" role="img" aria-label="QR code sekali pakai">
          <path :d="qr.qr.path" class="fill-ink-950" />
        </svg>
      </div>
      <div class="grid w-full gap-2">
        <div class="h-1.5 w-full overflow-hidden rounded-full bg-surface-2" aria-hidden="true">
          <div class="h-full rounded-full bg-accent transition-[width] duration-500 ease-linear" :style="{ width: `${pct}%` }"></div>
        </div>
        <p class="text-sm text-muted" aria-live="polite">
          <RefreshCw class="mr-1 inline size-4" aria-hidden="true" />Berganti otomatis dalam <span class="num text-fg">{{ left }}</span> detik
        </p>
      </div>
      <p class="text-sm text-muted">Minta lawan bicara men-scan dengan kamera ponselnya. Setiap QR hanya bisa dipakai sekali.</p>
    </template>

    <div v-else class="aspect-square w-full animate-skeleton rounded-lg bg-surface-2"></div>
  </div>
</template>
