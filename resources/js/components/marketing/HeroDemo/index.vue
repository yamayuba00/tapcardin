<script setup>
// Visual hero: produk nyata dalam satu pandangan — kartu dari template asli (CardSide) ditempel ke ponsel,
// dan hasilnya: profil publik yang sama dengan yang dilihat penerima (PublicProfileView mode pratinjau).
// Bingkai ponsel polos tanpa status bar palsu. Dirender juga di server (SSR) → tampil sebelum JS.
import { Check, Nfc } from 'lucide-vue-next'
import CardSide from '@/components/studio/CardSide/index.vue'
import PublicProfileView from '@/components/profile/PublicProfileView/index.vue'

defineProps({
  card: { type: Object, required: true }, // sisi depan template (preview.landscape.front)
  vars: { type: Object, required: true },
  qr: { type: Object, default: null },
  profile: { type: Object, required: true }, // contoh profil (bentuk PublicProfile)
})
</script>

<template>
  <figure class="relative mx-auto w-full max-w-[560px] pb-16 sm:pb-12" aria-label="Kartu TapCard ditempel ke ponsel, profil pemilik kartu langsung terbuka">
    <!-- kartu fisik (template Onyx, data contoh) -->
    <div class="relative z-10 mt-6 w-[76%] -rotate-6 overflow-hidden rounded-[14px] border border-line-strong sm:mt-10 sm:w-[70%]">
      <CardSide :side="card" :vars="vars" :qr="qr" :overlays="false" />
    </div>

    <!-- hasil tap: profil di ponsel penerima -->
    <div class="absolute top-0 right-0 z-20 w-[40%] sm:w-[44%]">
      <div class="overflow-hidden rounded-[26px] border-[5px] border-onyx-700 bg-bg">
        <div class="pointer-events-none relative h-[200px] overflow-hidden sm:h-[330px]" inert>
          <div class="absolute top-0 left-0 w-[390px] origin-top-left scale-[.33] sm:scale-[.62]">
            <PublicProfileView :profile="profile" preview />
          </div>
        </div>
      </div>
    </div>

    <!-- urutan kejadian -->
    <span class="absolute top-[12%] left-[44%] z-30 sm:top-[18%] sm:left-[46%] inline-flex items-center gap-1.5 rounded-full border border-line-strong bg-surface px-3 py-1.5 font-mono text-xs font-medium text-fg">
      <Nfc class="size-4 text-accent-text" aria-hidden="true" /> tap
    </span>
    <figcaption class="absolute bottom-0 left-2 z-30 flex items-center gap-3 rounded-md border border-line bg-surface px-3 py-2 text-sm sm:px-4 sm:py-3">
      <span class="grid size-8 flex-none place-items-center rounded-full bg-solid-ok text-on-solid"><Check class="size-4" aria-hidden="true" /></span>
      <span class="grid leading-tight"><strong class="font-semibold">Kontak tersimpan</strong><span class="text-xs text-muted sm:text-sm">tanpa aplikasi, di iPhone & Android</span></span>
    </figcaption>
  </figure>
</template>
