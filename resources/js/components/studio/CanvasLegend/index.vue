<script setup>
// Keterangan garis bantu kanvas — warna & gaya garisnya sama persis dengan yang digambar CardSide.
defineProps({ kind: { type: String, default: 'card' }, lanyard: Boolean, safe: { type: Number, default: 4 } })
</script>

<template>
  <ul class="flex flex-wrap items-center justify-center gap-x-4 gap-y-1.5 text-xs text-muted">
    <template v-if="kind === 'card'">
      <li class="flex items-center gap-1.5"><span class="h-0 w-5 border-t border-dashed border-sky-300" aria-hidden="true"></span> Batas aman teks {{ safe }} mm</li>
      <li class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-coral-300/30" aria-hidden="true"></span> Area potong (bleed) — bisa terpotong</li>
      <li class="flex items-center gap-1.5"><span class="size-3 rounded-sm border border-amber-300 bg-amber-300/20" aria-hidden="true"></span> Posisi chip NFC</li>
      <li v-if="lanyard" class="flex items-center gap-1.5"><span class="h-2 w-5 rounded-full border border-line-strong bg-bg" aria-hidden="true"></span> Lubang lanyard</li>
    </template>
    <template v-else>
      <li class="flex items-center gap-1.5"><span class="h-0 w-5 border-t border-dashed border-sky-300" aria-hidden="true"></span> Batas aman tepi tali {{ safe }} mm</li>
      <li class="flex items-center gap-1.5"><span class="h-3 w-0 border-l border-dashed border-coral-300" aria-hidden="true"></span> Ujung pola (diulang ke kiri & kanan)</li>
    </template>
  </ul>
</template>
