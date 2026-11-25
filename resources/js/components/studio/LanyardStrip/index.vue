<script setup>
// Pita lanyard utuh: pola (tile) diulang sepanjang `length` mm. Overlay menandai sambungan pola (garis putus-putus),
// margin tepi jahitan (atas & bawah), dan zona kait/klip di ujung tali yang tidak boleh berisi cetakan.
// Dipakai di bawah kanvas editor dan di pratinjau gantung. Nilai `safe` & `hardwareMm` mengikuti App\Support\Design\LanyardGeometry.
import { computed } from 'vue'
import CardSide from '@/components/studio/CardSide/index.vue'

const props = defineProps({
  side: { type: Object, required: true },
  kit: { type: Object, default: null },
  vars: { type: Object, default: () => ({}) },
  length: { type: Number, default: 900 }, // mm: panjang tali penuh
  scale: { type: Number, default: 1 }, // px per mm
  safe: { type: Number, default: 1.5 }, // margin tepi jahitan (mm), tiap sisi melintang
  hardwareMm: { type: Number, default: 25 }, // zona kait/klip di ujung tali (mm)
})
const tile = computed(() => props.side.size.w)
const height = computed(() => props.side.size.h)
const count = computed(() => Math.max(1, Math.ceil(props.length / tile.value)))
const seams = computed(() => Array.from({ length: count.value - 1 }, (_, i) => (i + 1) * tile.value * props.scale))
</script>

<template>
  <div class="relative flex overflow-hidden" :style="{ width: `${length * scale}px`, height: `${height * scale}px` }" aria-hidden="true">
    <div v-for="i in count" :key="i" class="flex-none" :style="{ width: `${tile * scale}px`, height: `${height * scale}px` }">
      <CardSide :side="side" :kit="kit" :vars="vars" trim />
    </div>

    <!-- margin jahitan atas & bawah: konten di sini bisa terpotong saat dijahit -->
    <div class="pointer-events-none absolute inset-x-0 top-0 bg-danger/15" :style="{ height: `${safe * scale}px` }" />
    <div class="pointer-events-none absolute inset-x-0 bottom-0 bg-danger/15" :style="{ height: `${safe * scale}px` }" />
    <!-- sambungan antar pola -->
    <div v-for="x in seams" :key="`s${x}`" class="pointer-events-none absolute inset-y-0 border-l border-dashed border-warn/70" :style="{ left: `${x}px` }" />
    <!-- zona kait/klip di ujung tali: tanpa cetakan -->
    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center justify-center bg-surface-2/85 text-[10px] font-medium text-muted [writing-mode:vertical-rl]"
      :style="{ width: `${hardwareMm * scale}px` }">Kait</div>
  </div>
</template>
