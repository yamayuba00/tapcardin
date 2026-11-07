<script setup>
// DBD §3.5 — batang solid --accent; seri kedua --text-2 ber-hatching; maks 2 seri; tanpa isian bergradasi.
// Batang digambar SVG yang meregang mengisi lebar & tinggi (preserveAspectRatio="none") agar tidak mengecil di ponsel;
// label tanggal & nilai maksimum berupa teks HTML sehingga ukurannya tetap terbaca di layar mana pun.
import { computed, useId } from 'vue'

const props = defineProps({
  series: { type: Array, required: true }, // [{date, value, [secondKey]?}]
  secondKey: { type: String, default: null },
  label: { type: String, default: 'Grafik' },
  height: { type: Number, default: 160 },
})

const W = 600
const hatchId = `hatch-${useId()}`
const max = computed(() => Math.max(1, ...props.series.map((p) => Math.max(p.value, props.secondKey ? p[props.secondKey] ?? 0 : 0))))
const bw = computed(() => W / Math.max(props.series.length, 1))
const h = (v) => ((props.height - 4) * v) / max.value
const short = (d) => (d ? `${d.slice(8, 10)}/${d.slice(5, 7)}` : '')
</script>

<template>
  <figure class="grid gap-1" role="img" :aria-label="`${label}, maksimum ${max}`">
    <div class="flex items-end justify-between gap-2 font-mono text-xs text-muted" aria-hidden="true">
      <span>maks. {{ max }}</span>
    </div>
    <svg class="block w-full text-accent" :style="{ height: `${height}px` }" :viewBox="`0 0 ${W} ${height}`" preserveAspectRatio="none" aria-hidden="true">
      <defs>
        <pattern :id="hatchId" width="4" height="4" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
          <line x1="0" y1="0" x2="0" y2="4" class="stroke-muted" stroke-width="1" />
        </pattern>
      </defs>
      <line class="stroke-line" x1="0" :y1="height - 0.5" :x2="W" :y2="height - 0.5" vector-effect="non-scaling-stroke" />
      <g v-for="(p, i) in series" :key="p.date">
        <rect class="fill-current hover:opacity-85" :x="i * bw + bw * 0.15" :y="height - h(p.value)" :width="Math.max(secondKey ? bw * 0.35 : bw * 0.7, 1)" :height="h(p.value)">
          <title>{{ p.date }}: {{ p.value }}</title>
        </rect>
        <rect v-if="secondKey" :fill="`url(#${hatchId})`" :x="i * bw + bw * 0.5" :y="height - h(p[secondKey] ?? 0)" :width="Math.max(bw * 0.35, 1)" :height="h(p[secondKey] ?? 0)">
          <title>{{ p.date }}: {{ p[secondKey] }}</title>
        </rect>
      </g>
    </svg>
    <figcaption class="flex justify-between font-mono text-xs text-muted" aria-hidden="true">
      <span>{{ short(series[0]?.date) }}</span>
      <span>{{ short(series[series.length - 1]?.date) }}</span>
    </figcaption>
  </figure>
</template>
