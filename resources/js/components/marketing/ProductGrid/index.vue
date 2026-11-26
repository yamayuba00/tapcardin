<script setup>
// Daftar produk untuk halaman utama: tiap produk = ikon, apa itu, untuk siapa, dan satu fakta.
// Fakta diisi dari katalog/config (lihat komentar di halaman Home), bukan teks bebas.
defineProps({
  items: { type: Array, required: true }, // { key, icon, name, what, who, fact }
})
</script>

<template>
  <ul class="grid gap-px overflow-hidden rounded-lg border border-line bg-line sm:grid-cols-2 lg:grid-cols-3">
    <li v-for="p in items" :key="p.key" class="lift-card grid content-start gap-3 bg-bg p-6">
      <span class="grid size-10 place-items-center rounded-md border border-line-strong">
        <component :is="p.icon" class="size-5 text-accent-text" aria-hidden="true" />
      </span>
      <h3 class="font-display text-xl font-bold">{{ p.name }}</h3>
      <p class="text-sm"><span class="text-muted">Apa:</span> {{ p.what }}</p>
      <p class="text-sm"><span class="text-muted">Untuk:</span> {{ p.who }}</p>
      <p class="mt-auto border-t border-line pt-3 text-sm font-medium text-accent-text">{{ p.fact }}</p>
    </li>
  </ul>
</template>
