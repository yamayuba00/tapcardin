<script setup>
// Pratinjau merchandise: siluet produk (kaos, jaket, tote bag, mug, topi) berwarna bahan, dengan desain area cetak
// diletakkan di tempatnya. Siluet hanya ilustrasi; ukuran cetak sebenarnya mengikuti area cetak produk (mm).
import { computed } from 'vue'
import CardSide from '@/components/studio/CardSide/index.vue'
import { resolveColor } from '@/lib/design'

const props = defineProps({
  silhouette: { type: String, required: true }, // tshirt | jacket | totebag | mug | cap
  side: { type: Object, required: true },
  which: { type: String, default: 'front' }, // front | back
  kit: { type: Object, default: null },
  vars: { type: Object, default: () => ({}) },
  showArea: Boolean, // garis putus-putus batas area cetak
})

// viewBox, bentuk (path isi + garis detail), dan kotak maksimum tempat area cetak diletakkan
const SHAPES = {
  tshirt: {
    vb: [400, 420],
    body: 'M120 20 L160 10 Q200 42 240 10 L280 20 L380 82 L342 150 L300 126 L300 410 L100 410 L100 126 L58 150 L20 82 Z',
    details: { front: ['M160 10 Q200 42 240 10'], back: ['M160 10 Q200 24 240 10'] },
    area: { x: 128, y: 82, w: 144, h: 210 },
  },
  jacket: {
    vb: [400, 440],
    body: 'M132 20 L166 10 Q200 34 234 10 L268 20 L338 48 L390 400 L342 412 L310 150 L310 430 L90 430 L90 150 L58 412 L10 400 L62 48 Z',
    details: { front: ['M200 30 L200 430', 'M166 10 L200 60 L234 10'], back: ['M166 10 Q200 22 234 10'] },
    area: { x: 112, y: 80, w: 176, h: 220 },
  },
  totebag: {
    vb: [360, 420],
    body: 'M40 130 L320 130 L320 410 L40 410 Z',
    under: 'M110 130 Q110 26 180 26 Q250 26 250 130', // pegangan, digambar di belakang badan tas
    details: { front: [], back: [] },
    area: { x: 66, y: 156, w: 228, h: 228 },
  },
  mug: {
    vb: [420, 300],
    body: 'M60 40 L320 40 L320 262 Q320 272 310 272 L70 272 Q60 272 60 262 Z',
    details: { front: ['M320 92 Q398 92 398 156 Q398 220 320 220', 'M320 112 Q376 112 376 156 Q376 200 320 200'], back: [] },
    area: { x: 70, y: 66, w: 240, h: 180 },
  },
  cap: {
    vb: [400, 300],
    body: 'M62 214 Q62 62 200 50 Q338 62 338 214 Z',
    brim: 'M40 214 Q200 296 360 214 Q200 246 40 214',
    details: { front: ['M200 50 L200 214'], back: [] },
    area: { x: 124, y: 104, w: 152, h: 86 },
  },
}

const shape = computed(() => SHAPES[props.silhouette] ?? SHAPES.tshirt)
const fabric = computed(() => resolveColor(props.side.background?.value, props.kit, 'white'))
// area cetak dipasang di tengah kotak maksimum dengan rasio asli (mm)
const box = computed(() => {
  const a = shape.value.area
  const r = props.side.size.w / props.side.size.h
  const w = Math.min(a.w, a.h * r)
  const h = w / r
  const [vw, vh] = shape.value.vb
  return { left: `${((a.x + (a.w - w) / 2) / vw) * 100}%`, top: `${((a.y + (a.h - h) / 2) / vh) * 100}%`, width: `${(w / vw) * 100}%`, height: `${(h / vh) * 100}%` }
})
const details = computed(() => shape.value.details[props.which] ?? shape.value.details.front)
</script>

<template>
  <div class="relative mx-auto w-full" :style="{ aspectRatio: `${shape.vb[0]} / ${shape.vb[1]}` }">
    <svg :viewBox="`0 0 ${shape.vb[0]} ${shape.vb[1]}`" class="absolute inset-0 size-full" role="img" :aria-label="`Pratinjau ${silhouette} sisi ${which === 'front' ? 'depan' : 'belakang'}`">
      <template v-if="shape.under">
        <path :d="shape.under" fill="none" class="stroke-line-strong" stroke-width="14" stroke-linecap="round" />
        <path :d="shape.under" fill="none" :stroke="fabric" stroke-width="10" stroke-linecap="round" />
      </template>
      <path :d="shape.body" :fill="fabric" class="stroke-line-strong" stroke-width="2" stroke-linejoin="round" />
      <path v-if="shape.brim" :d="shape.brim" :fill="fabric" class="stroke-line-strong" stroke-width="2" stroke-linejoin="round" />
      <path v-for="(d, i) in details" :key="i" :d="d" fill="none" class="stroke-line-strong" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
    </svg>
    <div class="absolute" :style="box">
      <CardSide :side="side" :vars="vars" :kit="kit" trim :overlays="false" />
      <span v-if="showArea" class="pointer-events-none absolute inset-0 border border-dashed border-sky-300" aria-hidden="true"></span>
    </div>
  </div>
</template>
