<script setup>
// Pita lanyard: pola (tile) diulang sepanjang `length` mm, ditampilkan horizontal. Dipakai di bawah kanvas editor
// (melihat sambungan pola) dan di pratinjau gantung (diputar dengan CSS).
import { computed } from 'vue'
import CardSide from '@/components/studio/CardSide/index.vue'

const props = defineProps({
  side: { type: Object, required: true },
  kit: { type: Object, default: null },
  vars: { type: Object, default: () => ({}) },
  length: { type: Number, default: 450 }, // mm yang ditampilkan
  scale: { type: Number, default: 2 }, // px per mm
})
const tile = computed(() => props.side.size.w)
const count = computed(() => Math.max(1, Math.ceil(props.length / tile.value)))
</script>

<template>
  <div class="flex overflow-hidden" :style="{ width: `${length * scale}px`, height: `${side.size.h * scale}px` }" aria-hidden="true">
    <div v-for="i in count" :key="i" class="flex-none" :style="{ width: `${tile * scale}px`, height: `${side.size.h * scale}px` }">
      <CardSide :side="side" :kit="kit" :vars="vars" trim />
    </div>
  </div>
</template>
