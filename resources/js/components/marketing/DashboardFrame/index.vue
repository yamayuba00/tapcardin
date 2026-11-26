<script setup>
// Path publik (public/), bukan impor Vite: dibaca langsung oleh peramban.
const SRC = '/marketing/dashboard-mockup.png'
// Bingkai perangkat untuk tangkapan dashboard (data contoh, bukan data pengguna nyata).
// Layar memakai object-position top: bagian atas (navigasi, ringkasan) selalu terlihat saat dipotong.
</script>

<template>
  <figure class="mx-auto w-full rounded-[28px] border border-line bg-surface-2 p-2 sm:p-3">
    <div class="flex h-9 items-center gap-2 px-3" aria-hidden="true">
      <span class="size-2.5 rounded-full bg-onyx-700"></span>
      <span class="size-2.5 rounded-full bg-onyx-700"></span>
      <span class="size-2.5 rounded-full bg-onyx-700"></span>
      <span class="ml-3 truncate text-xs text-muted">Dashboard TapCard · data contoh</span>
    </div>
    <div class="aspect-[4/5] overflow-hidden rounded-[18px] border border-line bg-bg sm:aspect-[16/9]">
      <img
        :src="SRC"
        width="1280"
        height="1000"
        alt="Tampilan dashboard TapCard dengan data contoh: ringkasan tap, grafik tap 30 hari, dan aktivitas terbaru"
        loading="lazy"
        decoding="async"
        class="block h-full w-full object-cover object-top"
      />
    </div>
  </figure>
</template>
