<script setup>
// Kerangka editor layar penuh (kartu & lanyard): bilah atas · alat kiri · kanvas tengah (scroll sendiri) · panel kanan.
// Layar < lg: bertumpuk dalam satu halaman yang di-scroll (kanvas tampil utuh, panel di bawahnya) dengan bilah atas
// menempel. Ponsel: baris 1 = kembali · nama · Simpan; kontrol lain (`max-sm:order-1` di halaman) turun ke baris 2.
defineProps({ busy: Boolean })
</script>

<template>
  <div class="grid min-h-dvh grid-cols-[minmax(0,1fr)] grid-rows-[auto_1fr] bg-bg lg:h-dvh lg:grid-rows-[auto_minmax(0,1fr)]">
    <header class="sticky top-0 z-20 flex min-w-0 flex-wrap items-center gap-2 border-b border-line bg-surface px-3 py-2 lg:static"><slot name="top" /></header>
    <div class="grid grid-cols-[minmax(0,1fr)] content-start lg:min-h-0 lg:grid-cols-[64px_minmax(0,1fr)_360px] lg:grid-rows-1 lg:content-stretch">
      <aside class="border-b border-line p-2 lg:overflow-y-auto lg:border-r lg:border-b-0 lg:scrollbar-none"><slot name="tools" /></aside>
      <main class="relative overflow-x-auto bg-surface-2 lg:min-h-0 lg:overflow-auto" :aria-busy="busy || undefined"><slot name="canvas" /></main>
      <aside class="border-t border-line bg-surface lg:overflow-y-auto lg:border-t-0 lg:border-l"><slot name="panel" /></aside>
    </div>
  </div>
</template>
