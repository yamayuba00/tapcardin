<script setup>
// DBD §3.5 — batang solid --accent; seri kedua --text-2 ber-hatching; maks 2 seri; tanpa isian bergradasi.
import { computed, useId } from 'vue'

const props = defineProps({
  series: { type: Array, required: true }, // [{date, value, [secondKey]?}]
  secondKey: { type: String, default: null },
  label: { type: String, default: 'Grafik' },
  height: { type: Number, default: 160 },
})

const W = 600
const pad = 22
const hatchId = `hatch-${useId()}`
const max = computed(() => Math.max(1, ...props.series.map((p) => Math.max(p.value, props.secondKey ? p[props.secondKey] ?? 0 : 0))))
const bw = computed(() => (W - 8) / Math.max(props.series.length, 1))
const h = (v) => ((props.height - pad - 8) * v) / max.value
const short = (d) => (d ? `${d.slice(8, 10)}/${d.slice(5, 7)}` : '')
</script>

<template>
  <svg class="h-40 w-full text-accent" :viewBox="`0 0 ${W} ${height}`" role="img" :aria-label="`${label}, maksimum ${max}`">
    <defs>
      <pattern :id="hatchId" width="4" height="4" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
        <line x1="0" y1="0" x2="0" y2="4" class="stroke-muted" stroke-width="1" />
      </pattern>
    </defs>
    <line class="stroke-line" x1="0" :y1="height - pad" :x2="W" :y2="height - pad" />
    <g v-for="(p, i) in series" :key="p.date">
      <rect class="fill-current hover:opacity-85" :x="4 + i * bw + 1" :y="height - pad - h(p.value)" :width="Math.max(secondKey ? bw / 2 - 2 : bw - 3, 1)" :height="h(p.value)" rx="2">
        <title>{{ p.date }}: {{ p.value }}</title>
      </rect>
      <rect v-if="secondKey" :fill="`url(#${hatchId})`" :x="4 + i * bw + bw / 2" :y="height - pad - h(p[secondKey] ?? 0)" :width="Math.max(bw / 2 - 2, 1)" :height="h(p[secondKey] ?? 0)" rx="2">
        <title>{{ p.date }}: {{ p[secondKey] }}</title>
      </rect>
    </g>
    <text class="fill-muted font-mono text-[11px]" x="4" :y="height - 4">{{ short(series[0]?.date) }}</text>
    <text class="fill-muted font-mono text-[11px]" :x="W - 4" :y="height - 4" text-anchor="end">{{ short(series[series.length - 1]?.date) }}</text>
    <text class="fill-muted font-mono text-[11px]" :x="W - 4" y="12" text-anchor="end">{{ max }}</text>
  </svg>
</template>
