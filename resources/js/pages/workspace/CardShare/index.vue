<script setup>
// Bagikan kartu tanpa kartu fisik — QR besar di layar (layar tetap menyala), tautan permanen & tautan @nama,
// salin, WhatsApp, dan menu bagikan bawaan ponsel. Gratis untuk semua paket; QR aman sekali pakai tetap fitur Pro.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowLeft, AtSign, Copy, Link2, Lock, MessageCircle, Share2 } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import CardVisual from '@/components/cards/CardVisual/index.vue'
import { cardsApi } from '@/api/workspace'
import { useAsync } from '@/composables/useAsync'
import { useToastStore } from '@/stores/toast'

const route = useRoute()
const token = route.params.token
const toast = useToastStore()
const { data: card, error } = useAsync(() => cardsApi.get(token))
const name = computed(() => card.value?.profile?.display_name ?? 'kartu saya')
const best = computed(() => card.value?.share?.handle_url ?? card.value?.share?.link)
const message = computed(() => `Halo! Ini kontak saya (${name.value}) — simpan dengan satu ketukan: ${best.value}`)
const canShare = typeof navigator !== 'undefined' && !!navigator.share

async function copy(text, what) {
  try {
    await navigator.clipboard.writeText(text)
    toast.ok(`${what} disalin.`)
    cardsApi.shared(token, 'copy')
  } catch {
    toast.error('Tidak bisa menyalin — tekan lama tautan untuk menyalin manual.')
  }
}
async function share() {
  try {
    await navigator.share({ title: name.value, text: `Kontak ${name.value}`, url: best.value })
    cardsApi.shared(token, 'native')
  } catch { /* dibatalkan pengguna */ }
}
const waUrl = computed(() => `https://wa.me/?text=${encodeURIComponent(message.value)}`)

let wake = null
onMounted(async () => {
  wake = await navigator.wakeLock?.request('screen').catch(() => null)
  cardsApi.shared(token, 'qr') // QR ditampilkan di layar untuk di-scan
})
onBeforeUnmount(() => wake?.release?.())
</script>

<template>
  <div class="mx-auto grid w-full max-w-[440px] justify-items-center gap-6 py-4 text-center">
    <div class="flex w-full items-center justify-between">
      <AppButton variant="ghost" :to="`/cards/${token}`"><ArrowLeft class="size-5" /> Kartu</AppButton>
      <span class="label inline-flex items-center gap-1"><Share2 class="size-4" /> Bagikan</span>
    </div>

    <p v-if="error" class="text-danger" role="alert">{{ error.message }}</p>
    <template v-else-if="card">
      <div class="grid gap-1">
        <h1 class="font-display text-2xl font-bold">{{ name }}</h1>
        <p class="text-sm text-muted">Minta lawan bicara men-scan dengan kamera ponselnya — tanpa aplikasi.</p>
      </div>

      <div v-if="card.form === 'digital'" class="w-full"><CardVisual :token="token" :name="name" /></div>

      <!-- QR selalu gelap di atas terang agar terbaca semua kamera, apa pun tema aplikasi -->
      <div class="w-full rounded-lg bg-bone-0 p-5" data-theme="light">
        <img :src="card.qr_svg_url" :alt="`QR bio link ${name}`" class="block aspect-square w-full" width="360" height="360" />
      </div>
      <p v-if="card.qr_rotation" class="text-sm text-muted">QR ini berganti tiap {{ card.qr_rotation.days }} hari (paket Free) — yang tampil di sini selalu yang terbaru.</p>

      <div class="grid w-full gap-2 text-left">
        <div v-if="card.share.handle_url" class="flex items-center gap-2 rounded-md border border-line bg-surface px-3 py-2">
          <AtSign class="size-5 flex-none text-accent-text" aria-hidden="true" />
          <span class="min-w-0 flex-1 truncate font-mono text-sm">{{ card.share.handle_url.replace(/^https?:\/\//, '') }}</span>
          <AppButton variant="ghost" icon aria-label="Salin tautan @nama" @click="copy(card.share.handle_url, 'Tautan')"><Copy class="size-4" /></AppButton>
        </div>
        <div class="flex items-center gap-2 rounded-md border border-line bg-surface px-3 py-2">
          <Link2 class="size-5 flex-none text-muted" aria-hidden="true" />
          <span class="min-w-0 flex-1 truncate font-mono text-sm">{{ card.share.link.replace(/^https?:\/\//, '') }}</span>
          <AppButton variant="ghost" icon aria-label="Salin tautan kartu" @click="copy(card.share.link, 'Tautan')"><Copy class="size-4" /></AppButton>
        </div>
        <p v-if="!card.share.handle_url && !card.share.handle" class="text-sm text-muted">
          Ingin tautan pendek seperti <span class="font-mono">tap…/@nama</span>? <RouterLink to="/settings">Atur nama pengguna</RouterLink>.
        </p>
      </div>

      <div class="grid w-full gap-2 sm:grid-cols-2">
        <AppButton :href="waUrl" target="_blank" rel="noopener" @click="cardsApi.shared(token, 'whatsapp')"><MessageCircle class="size-5" /> Kirim via WhatsApp</AppButton>
        <AppButton v-if="canShare" variant="secondary" @click="share"><Share2 class="size-5" /> Bagikan…</AppButton>
        <AppButton v-else variant="secondary" @click="copy(message, 'Pesan')"><Copy class="size-5" /> Salin pesan</AppButton>
      </div>

      <AppButton variant="ghost" :to="`/cards/${token}/qr`"><Lock class="size-4" /> QR aman sekali pakai (Pro)</AppButton>
    </template>
    <AppSkeleton v-else class="!aspect-square !h-auto w-full" />
  </div>
</template>
