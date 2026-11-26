<script setup>
// Visual hero: kartu contoh ditempel ke ponsel, dan hasilnya profil contoh (PublicProfileView mode pratinjau).
// Data generik (Nama Anda, Jabatan Anda), tanpa angka tap: tidak ada penghitung yang terlihat hidup.
// Satu kanvas 560×344 yang diskalakan lewat transform: desktop 100%, tablet 75%, ponsel 63% (muat 353px).
// Dirender juga di server (SSR) → tampil sebelum JS. Chip "tap" hanya memutar ulang cincin NFC.
import { ref } from 'vue'
import { Check, Nfc } from 'lucide-vue-next'
import CardSide from '@/components/studio/CardSide/index.vue'
import PublicProfileView from '@/components/profile/PublicProfileView/index.vue'

defineProps({
  card: { type: Object, required: true }, // sisi depan template (preview.landscape.front)
  vars: { type: Object, required: true },
  qr: { type: Object, default: null },
  profile: { type: Object, required: true }, // contoh profil (bentuk PublicProfile)
})

const tapKey = ref(0) // naik tiap klik → cincin dipasang ulang
</script>

<template>
  <figure
    class="relative mx-auto h-[217px] w-[353px] sm:h-[258px] sm:w-[420px] lg:h-[344px] lg:w-[560px]"
    aria-label="Contoh: kartu ditempel ke ponsel, profil pemilik kartu langsung terbuka"
  >
    <div class="absolute top-0 left-0 h-[344px] w-[560px] origin-top-left scale-[.63] sm:scale-75 lg:scale-100">
      <!-- kartu fisik (template Onyx, data contoh) -->
      <div class="absolute top-10 left-7 z-10 w-[364px] -rotate-6 overflow-hidden rounded-[14px] border border-line-strong">
        <CardSide :side="card" :vars="vars" :qr="qr" :overlays="false" />
      </div>

      <!-- hasil tap: profil contoh di ponsel penerima -->
      <div class="absolute top-0 right-0 z-20 w-[246px]">
        <div class="overflow-hidden rounded-[26px] border-[5px] border-onyx-700 bg-bg">
          <div class="pointer-events-none relative h-[330px] overflow-hidden" inert>
            <div class="absolute top-0 left-0 w-[390px] origin-top-left scale-[.6]">
              <PublicProfileView :profile="profile" preview />
            </div>
          </div>
        </div>
      </div>

      <!-- chip tap (bisa diklik) + cincin gelombang NFC -->
      <span class="absolute top-[96px] left-[236px] z-30">
        <template v-if="tapKey">
          <span
            v-for="r in 3" :key="`${tapKey}-${r}`" aria-hidden="true"
            class="tap-ring pointer-events-none absolute inset-0 rounded-full border-2 border-accent"
            :style="{ animationDelay: `${(r - 1) * 160}ms` }"
          ></span>
        </template>
        <button
          type="button" aria-label="Putar ulang animasi tap contoh"
          class="relative inline-flex min-h-11 cursor-pointer items-center gap-1.5 rounded-full border border-line-strong bg-surface px-3 py-1.5 font-mono text-xs font-medium text-fg transition-transform active:scale-95"
          @click="tapKey++"
        >
          <Nfc class="size-4 text-accent-text" aria-hidden="true" /> tap
        </button>
      </span>

      <figcaption class="absolute top-[236px] left-4 z-30 flex items-center gap-3 rounded-md border border-line bg-surface px-4 py-3 text-sm">
        <span class="grid size-8 flex-none place-items-center rounded-full bg-solid-ok text-on-solid"><Check class="size-4" aria-hidden="true" /></span>
        <span class="grid leading-tight"><strong class="font-semibold">Kontak tersimpan</strong><span class="text-sm text-muted">contoh ilustrasi · tanpa aplikasi</span></span>
      </figcaption>
    </div>
  </figure>
</template>
