<script setup>
// Kartu KPI beranda (referensi): ubin ikon, judul, angka utama, keterangan, dan pil delta hijau/merah.
// Pil hanya muncul bila ada pembanding nyata dari API; tanpa pembanding tidak ada pil (tidak mengarang angka).
import { ArrowDown, ArrowUp } from 'lucide-vue-next'

defineProps({
  icon: { type: [Object, Function], required: true },
  label: { type: String, required: true },
  value: { type: [String, Number], required: true },
  sub: { type: String, default: '' },
  delta: { type: Object, default: null }, // { value: persen absolut, up: boolean } dari deltaPill()
})
</script>

<template>
  <div class="grid min-w-0 content-start gap-3 rounded-md border border-line bg-surface p-4 sm:p-5">
    <div class="flex min-w-0 items-center gap-3">
      <span class="grid size-9 flex-none place-items-center rounded-md bg-surface-2 text-accent-text sm:size-10" aria-hidden="true">
        <component :is="icon" class="size-5" />
      </span>
      <span class="min-w-0 text-sm leading-snug font-medium text-muted">{{ label }}</span>
    </div>
    <div class="flex min-w-0 items-end justify-between gap-2">
      <span class="num min-w-0 break-words text-2xl leading-none font-semibold sm:text-[28px]">{{ value }}</span>
      <span
        v-if="delta"
        :class="['inline-flex flex-none items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium', delta.up ? 'border-ok text-ok' : 'border-danger text-danger']"
      >
        <component :is="delta.up ? ArrowUp : ArrowDown" class="size-3.5" aria-hidden="true" />
        <span>{{ delta.value }}%</span>
        <span class="sr-only">{{ delta.up ? 'naik' : 'turun' }}</span>
      </span>
    </div>
    <span v-if="sub" class="text-xs text-muted sm:text-sm">{{ sub }}</span>
  </div>
</template>
