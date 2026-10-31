<script setup>
// Kerangka editor layar penuh (kartu & lanyard): bilah atas · alat kiri · kanvas tengah (scroll sendiri) · panel kanan.
// Di layar kecil bertumpuk; panel kanan & kanvas masing-masing punya scroll agar bilah atas tetap terlihat.
defineProps({ busy: Boolean })
</script>

<template>
  <div class="grid h-dvh grid-rows-[auto_minmax(0,1fr)] bg-bg">
    <header class="flex min-w-0 flex-wrap items-center gap-2 border-b border-line bg-surface px-3 py-2"><slot name="top" /></header>
    <div class="grid min-h-0 grid-rows-[auto_minmax(0,1fr)_auto] lg:grid-cols-[64px_minmax(0,1fr)_360px] lg:grid-rows-1">
      <aside class="border-b border-line p-2 lg:overflow-y-auto lg:border-r lg:border-b-0 lg:scrollbar-none"><slot name="tools" /></aside>
      <main class="relative min-h-0 overflow-auto bg-surface-2" :aria-busy="busy || undefined"><slot name="canvas" /></main>
      <aside class="max-h-[45dvh] overflow-y-auto border-t border-line bg-surface lg:max-h-none lg:border-t-0 lg:border-l"><slot name="panel" /></aside>
    </div>
  </div>
</template>
