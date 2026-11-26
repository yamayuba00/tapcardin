<script setup>
// Animasi "cara kerja" dari tap sampai kontak tersimpan: 4 langkah (dekatkan → tap → profil terbuka → simpan).
// Dijalankan saat bagian masuk layar (sekali), atau lewat tombol Ulangi. Dengan prefers-reduced-motion atau tanpa JS,
// yang tampil langsung bingkai akhir (langkah 4). Teks langkah selalu terlihat di HTML server.
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { Check, Nfc, UserPlus } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import CardSide from '@/components/studio/CardSide/index.vue'
import PublicProfileView from '@/components/profile/PublicProfileView/index.vue'
import { prefersReducedMotion } from '@/lib/motion'

defineProps({
  card: { type: Object, required: true }, // sisi depan template contoh
  vars: { type: Object, required: true },
  qr: { type: Object, default: null },
  profile: { type: Object, required: true }, // contoh profil (bentuk PublicProfile)
})

const STEPS = [
  { title: 'Dekatkan ponsel', text: 'Penerima mendekatkan ponselnya ke kartu Anda.' },
  { title: 'Tap', text: 'Chip NFC terbaca (atau QR dipindai). Tanpa install aplikasi.' },
  { title: 'Profil terbuka', text: 'Profil Anda langsung tampil di browser ponsel penerima.' },
  { title: 'Simpan kontak', text: 'Penerima menekan "Simpan ke Kontak HP". Koneksi masuk ke dashboard Anda.' },
]
// jadwal (ms) saat setiap langkah mulai: 0 → 1 → 2 → 3
const AT = [0, 900, 1900, 3100]
const FINAL = STEPS.length - 1

const step = ref(FINAL) // SSR: bingkai akhir; klien memutar ulang setelah tampil
const root = ref(null)
const timers = []
let observer = null
let reduced = false

function clearTimers() {
  while (timers.length) window.clearTimeout(timers.pop())
}

function replay() {
  if (reduced) { step.value = FINAL; return }
  clearTimers()
  step.value = 0
  for (let i = 1; i <= FINAL; i++) timers.push(window.setTimeout(() => { step.value = i }, AT[i]))
}

onMounted(() => {
  reduced = prefersReducedMotion()
  if (reduced || typeof IntersectionObserver === 'undefined' || !root.value) return
  step.value = 0 // mulai dari awal: animasi berjalan saat bagian terlihat
  observer = new IntersectionObserver((entries) => {
    if (!entries.some((e) => e.isIntersecting)) return
    observer.disconnect()
    observer = null
    replay()
  }, { threshold: 0.35 })
  observer.observe(root.value)
})
onBeforeUnmount(() => {
  clearTimers()
  observer?.disconnect()
})
</script>

<template>
  <div ref="root" class="grid gap-10 lg:grid-cols-[.85fr_1.15fr] lg:items-center">
    <ol class="grid content-start gap-5" aria-label="Langkah tap sampai kontak tersimpan">
      <li v-for="(s, i) in STEPS" :key="s.title" class="flex gap-4" :class="step >= i ? 'text-fg' : 'text-muted'" :aria-current="step === i ? 'step' : undefined">
        <span
          class="grid size-9 flex-none place-items-center rounded-full border font-mono text-sm transition-colors duration-300 motion-reduce:transition-none"
          :class="step >= i ? 'border-accent bg-accent text-accent-ink' : 'border-line-strong text-muted'"
        >{{ i + 1 }}</span>
        <span class="grid gap-1">
          <strong class="font-display text-lg font-bold">{{ s.title }}</strong>
          <span class="text-sm text-muted">{{ s.text }}</span>
        </span>
      </li>
      <li class="pt-2">
        <AppButton variant="secondary" type="button" data-event="cta_click" data-location="how_it_works_replay" @click="replay">Ulangi animasi</AppButton>
      </li>
    </ol>

    <div class="grid gap-6 sm:grid-cols-3 sm:items-center sm:justify-items-center" aria-hidden="true">
      <!-- 1. kartu fisik -->
      <div class="relative w-full max-w-[220px] -rotate-3 overflow-hidden rounded-[14px] border border-line-strong">
        <CardSide :side="card" :vars="vars" :qr="qr" :overlays="false" />
      </div>

      <!-- 2 & 3. ponsel: tap → profil naik dari bawah -->
      <div class="relative">
        <span class="absolute -left-4 top-10 z-30 grid place-items-center">
          <span v-if="step === 1" class="tap-ring pointer-events-none absolute size-10 rounded-full border-2 border-accent" style="animation-delay: 0ms"></span>
          <span v-if="step === 1" class="tap-ring pointer-events-none absolute size-10 rounded-full border-2 border-accent" style="animation-delay: 160ms"></span>
          <span
            class="relative inline-flex items-center gap-1.5 rounded-full border border-line-strong bg-surface px-3 py-1.5 font-mono text-xs text-fg transition-opacity duration-300 motion-reduce:transition-none"
            :class="step === 1 ? 'opacity-100' : 'opacity-0'"
          ><Nfc class="size-4 text-accent-text" /> tap</span>
        </span>
        <div class="w-[200px] overflow-hidden rounded-[26px] border-[5px] border-onyx-700 bg-bg">
          <div class="relative h-[340px] overflow-hidden">
            <p class="absolute inset-x-0 top-1/2 -translate-y-1/2 px-4 text-center text-xs text-muted transition-opacity duration-300 motion-reduce:transition-none" :class="step < 2 ? 'opacity-100' : 'opacity-0'">Menunggu tap…</p>
            <div
              class="absolute inset-0 transition-transform duration-700 ease-out motion-reduce:transition-none"
              :class="step >= 2 ? 'translate-y-0' : 'translate-y-full'"
            >
              <div class="pointer-events-none absolute top-0 left-0 w-[390px] origin-top-left scale-[.5]" inert>
                <PublicProfileView :profile="profile" preview />
              </div>
            </div>
            <!-- 4. kontak tersimpan -->
            <span
              class="absolute inset-x-3 bottom-3 flex items-center gap-2 rounded-md border border-line bg-surface px-3 py-2 text-xs transition-all duration-500 motion-reduce:transition-none"
              :class="step >= FINAL ? 'translate-y-0 opacity-100' : 'translate-y-3 opacity-0'"
            >
              <span class="grid size-6 flex-none place-items-center rounded-full bg-solid-ok text-on-solid"><Check class="size-3.5" /></span>
              Kontak tersimpan
            </span>
          </div>
        </div>
      </div>

      <!-- 4. dashboard pemilik: lead baru muncul -->
      <div class="grid w-full max-w-[260px] gap-3 rounded-lg border border-line bg-surface p-4 text-sm">
        <span class="font-mono text-[11px] tracking-[.12em] text-muted uppercase">Dashboard Anda · contoh</span>
        <ul class="grid divide-y divide-line">
          <li class="flex items-center gap-3 py-2.5">
            <span class="grid size-7 flex-none place-items-center rounded-full border border-line-strong text-muted"><UserPlus class="size-3.5" /></span>
            <span class="grid min-w-0 flex-1"><strong class="truncate font-semibold">Tamu A</strong><span class="truncate text-xs text-muted">Lewat NFC</span></span>
          </li>
          <li
            class="flex items-center gap-3 py-2.5 transition-all duration-500 motion-reduce:transition-none"
            :class="step >= FINAL ? 'translate-y-0 opacity-100' : 'translate-y-2 opacity-0'"
          >
            <span class="grid size-7 flex-none place-items-center rounded-full bg-accent text-accent-ink"><UserPlus class="size-3.5" /></span>
            <span class="grid min-w-0 flex-1"><strong class="truncate font-semibold">Tamu B</strong><span class="truncate text-xs text-accent-text">Baru saja · lewat NFC</span></span>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>
