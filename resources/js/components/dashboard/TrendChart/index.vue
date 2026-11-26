<script setup>
// Grafik tren beranda (referensi): garis + area berisi solid --accent (tanpa gradien), titik penanda, tooltip
// tanggal & nilai saat hover/sentuh/panah keyboard. Koordinat dari lib/dashboard.js (chartPoints, nearestIndex).
// viewBox dengan lebar tetap (bukan preserveAspectRatio none) agar titik penanda tidak gepeng di ponsel.
import { computed, ref } from 'vue'
import { chartPoints, nearestIndex, shortDay } from '@/lib/dashboard'

const props = defineProps({
  series: { type: Array, required: true }, // [{ date: 'YYYY-MM-DD', value: number }]
  label: { type: String, default: 'Grafik tap' },
})

const W = 600
const H = 240
const PAD = 16
const wrap = ref(null)
const hover = ref(null)

const points = computed(() => chartPoints(props.series.map((p) => p.value), { width: W, height: H, pad: PAD }))
const max = computed(() => Math.max(0, ...props.series.map((p) => p.value)))
const line = computed(() => points.value.map((p, i) => `${i ? 'L' : 'M'}${p.x} ${p.y}`).join(' '))
const area = computed(() => (points.value.length ? `${line.value} L${points.value.at(-1).x} ${H} L${points.value[0].x} ${H} Z` : ''))
const idx = computed(() => hover.value ?? props.series.length - 1)
const cur = computed(() => points.value[idx.value])
const curDay = computed(() => props.series[idx.value])
const gridYs = computed(() => [1, 2, 3].map((i) => PAD + ((H - PAD * 2) * i) / 4))
// posisi tooltip dalam persen kotak; dijepit agar tidak keluar dari kartu di tepi
const tipLeft = computed(() => `clamp(64px, ${((cur.value?.x ?? 0) / W) * 100}%, calc(100% - 64px))`)
const tipTop = computed(() => `${((cur.value?.y ?? 0) / H) * 100}%`)

function onPointer(e) {
  const r = wrap.value.getBoundingClientRect()
  hover.value = nearestIndex(points.value, ((e.clientX - r.left) / r.width) * W)
}
function onKey(e) {
  const n = props.series.length
  if (!n) return
  if (e.key === 'ArrowLeft') hover.value = Math.max(0, idx.value - 1)
  else if (e.key === 'ArrowRight') hover.value = Math.min(n - 1, idx.value + 1)
  else return
  e.preventDefault()
}
</script>

<template>
  <figure v-if="series.length" class="grid min-w-0 gap-2" :aria-label="`${label}, ${series.length} hari, maksimum ${max}`">
    <figcaption class="sr-only">{{ label }}</figcaption>
    <div class="flex justify-between text-xs text-muted" aria-hidden="true"><span>maks. {{ max }}</span></div>
    <div
      ref="wrap" tabindex="0"
      class="relative min-w-0 touch-pan-y outline-none focus-visible:outline-2 focus-visible:outline-accent"
      @pointermove="onPointer" @pointerdown="onPointer" @pointerleave="hover = null" @keydown="onKey" @blur="hover = null"
    >
      <svg :viewBox="`0 0 ${W} ${H}`" class="block h-auto w-full overflow-visible" aria-hidden="true">
        <line v-for="y in gridYs" :key="y" class="stroke-line" x1="0" :x2="W" :y1="y" :y2="y" stroke-width="1" stroke-dasharray="4 6" vector-effect="non-scaling-stroke" />
        <path :d="area" class="fill-accent/15" />
        <path :d="line" fill="none" class="stroke-accent" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
        <line v-if="cur" class="stroke-line" :x1="cur.x" :x2="cur.x" :y1="cur.y" :y2="H" stroke-width="1" vector-effect="non-scaling-stroke" />
        <circle v-if="cur" :cx="cur.x" :cy="cur.y" r="6" class="fill-accent stroke-surface" stroke-width="3" />
      </svg>
      <div
        v-if="cur && curDay"
        class="pointer-events-none absolute grid gap-0.5 rounded-md border border-line-strong bg-surface-2 px-3 py-2 text-center whitespace-nowrap"
        :style="{ left: tipLeft, top: tipTop, transform: 'translate(-50%, calc(-100% - 12px))' }"
      >
        <span class="text-xs text-muted">{{ shortDay(curDay.date) }}</span>
        <span class="num text-sm font-semibold">{{ curDay.value }} tap</span>
      </div>
    </div>
    <div class="flex justify-between font-mono text-xs text-muted" aria-hidden="true">
      <span>{{ shortDay(series[0]?.date) }}</span>
      <span>{{ shortDay(series[Math.floor((series.length - 1) / 2)]?.date) }}</span>
      <span>{{ shortDay(series.at(-1)?.date) }}</span>
    </div>
  </figure>
</template>
