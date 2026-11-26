<script setup>
// Ticker kata kunci fitur: auto-geser pelan (transform saja), jeda saat hover/fokus. Dengan prefers-reduced-motion
// salinan kedua disembunyikan dan daftar tampil statis (bisa dibungkus). Satu-satunya loop selain dot hero.
const ITEMS = ['Satu tap', 'NFC + QR', 'Tanpa aplikasi', 'Bio link per kartu', 'Lead otomatis', 'Analitik tap jujur', 'Lanyard serasi', 'Backup harian', 'Tukar kontak']
</script>

<template>
  <div class="ticker overflow-hidden border-y border-line bg-surface py-4">
    <div class="ticker-track flex w-max motion-reduce:w-full">
      <ul v-for="copy in 2" :key="copy" :aria-hidden="copy === 2 ? 'true' : undefined" :class="['flex shrink-0 items-center gap-x-8 pr-8 text-sm font-medium text-muted', copy === 2 ? 'motion-reduce:hidden' : '', 'motion-reduce:w-full motion-reduce:flex-wrap motion-reduce:justify-center motion-reduce:pr-0']">
        <li v-for="item in ITEMS" :key="item" class="flex items-center gap-8 whitespace-nowrap">
          <span>{{ item }}</span>
          <span class="size-1.5 rounded-full bg-accent" aria-hidden="true"></span>
        </li>
      </ul>
    </div>
  </div>
</template>
