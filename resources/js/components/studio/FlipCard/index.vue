<script setup>
// Kartu dua sisi dengan animasi flip 3D (transform waktu, bukan gradasi — DBD §3.4).
// Rasio kanvas mengikuti ukuran + bleed sisi depan; semua prop editor diteruskan ke CardSide.
import { computed } from 'vue'
import CardSide from '@/components/studio/CardSide/index.vue'

const props = defineProps({
  front: { type: Object, required: true },
  back: { type: Object, required: true },
  flipped: Boolean,
  vars: { type: Object, default: () => ({}) },
  kit: { type: Object, default: null },
  qr: { type: Object, default: null },
  editable: Boolean,
  selection: { type: Array, default: () => [] },
  grid: { type: Number, default: 0 },
  snap: { type: Boolean, default: true },
  overlays: { type: Boolean, default: true },
  hoverFlip: Boolean,
})
const emit = defineEmits(['select', 'change', 'commit'])

const bleed = computed(() => props.front.size.bleed ?? 3)
const aspect = computed(() => `${props.front.size.w + 2 * bleed.value} / ${props.front.size.h + 2 * bleed.value}`)
const common = computed(() => ({ vars: props.vars, kit: props.kit, qr: props.qr, selection: props.selection, grid: props.grid, snap: props.snap, overlays: props.overlays }))
const on = { onSelect: (ids) => emit('select', ids), onChange: (e) => emit('change', e), onCommit: () => emit('commit') }
</script>

<template>
  <div :class="['group [perspective:1600px]', hoverFlip ? 'cursor-pointer' : '']" :style="{ aspectRatio: aspect }">
    <div
      :class="[
        'relative size-full transition-transform duration-700 ease-[cubic-bezier(.2,.8,.2,1)] [transform-style:preserve-3d]',
        flipped ? '[transform:rotateY(180deg)]' : '',
        hoverFlip ? 'group-hover:[transform:rotateY(180deg)]' : '',
      ]"
    >
      <div class="absolute inset-0 [backface-visibility:hidden]" :inert="flipped || undefined">
        <CardSide :side="front" v-bind="{ ...common, ...on }" :editable="editable && !flipped" />
      </div>
      <div class="absolute inset-0 [backface-visibility:hidden] [transform:rotateY(180deg)]" :inert="!flipped || undefined">
        <CardSide :side="back" v-bind="{ ...common, ...on }" :editable="editable && flipped" />
      </div>
    </div>
  </div>
</template>
