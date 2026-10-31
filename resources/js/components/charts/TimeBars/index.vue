<script setup>
// Batang per hari satu seri (perubahan dari waktu ke waktu): --accent solid, jarak antar batang, grid samar.
// Tinggi tetap (px) — SVG direntang selebar wadah (preserveAspectRatio none), label sumbu memakai HTML agar
// ukuran huruf tidak ikut membesar di layar lebar. Hover/fokus = garis bantu + tooltip; ada tabel untuk pembaca layar.
import { computed, ref } from 'vue'
import { num } from '@/lib/format'

const props = defineProps({
  series: { type: Array, required: true }, // [{ date: 'YYYY-MM-DD', value }]
  label: { type: String, required: true },
  height: { type: Number, default: 180 }, // px area plot
  format: { type: Function, default: num }, // format nilai (mis. rupiah)
})
const n = computed(() => Math.max(props.series.length, 1))
// garis atas dibulatkan ke angka rapi (1-2-5 × 10ⁿ) agar mudah dibaca
const max = computed(() => {
  const m = Math.max(1, ...props.series.map((p) => p.value))
  if (m <= 5) return Math.max(m, 2)
  const mag = 10 ** Math.floor(Math.log10(m))
  const k = m / mag
  return (k <= 1 ? 1 : k <= 2 ? 2 : k <= 5 ? 5 : 10) * mag
})
const pct = (v) => (v / max.value) * 100
const hover = ref(null)
const fmt = (d, long = false) => new Date(`${d}T00:00:00`).toLocaleDateString('id-ID', long ? { weekday: 'short', day: 'numeric', month: 'short' } : { day: 'numeric', month: 'short' })
const ticks = computed(() => {
  const len = props.series.length
  return len ? [0, Math.floor((len - 1) / 2), len - 1].filter((v, i, a) => a.indexOf(v) === i) : []
})
const total = computed(() => props.series.reduce((s, p) => s + p.value, 0))
const tip = computed(() => (hover.value === null ? null : props.series[hover.value]))
const left = (i) => ((i + 0.5) / n.value) * 100

function onMove(e) {
  const r = e.currentTarget.getBoundingClientRect()
  hover.value = Math.min(Math.max(Math.floor(((e.clientX - r.left) / r.width) * n.value), 0), n.value - 1)
}
function onKey(e) {
  if (!['ArrowLeft', 'ArrowRight'].includes(e.key)) return
  e.preventDefault()
  const cur = hover.value ?? n.value - 1
  hover.value = Math.min(Math.max(cur + (e.key === 'ArrowRight' ? 1 : -1), 0), n.value - 1)
}
</script>

<template>
  <div class="grid gap-1.5">
    <div class="relative">
      <span class="pointer-events-none absolute -top-1 right-0 font-mono text-[11px] text-muted">{{ format(max) }}</span>
      <div
        class="relative mt-4 border-b border-line-strong outline-none focus-visible:outline-2 focus-visible:outline-accent"
        :style="{ height: `${height}px` }"
        role="img" tabindex="0"
        :aria-label="`${label}: total ${total}, puncak ${Math.max(0, ...series.map((p) => p.value))} per hari. Panah kiri/kanan untuk membaca per hari.`"
        @mousemove="onMove" @mouseleave="hover = null" @keydown="onKey" @blur="hover = null"
      >
        <div class="pointer-events-none absolute inset-x-0 top-0 border-t border-dashed border-line" aria-hidden="true"></div>
        <div class="pointer-events-none absolute inset-x-0 top-1/2 border-t border-dashed border-line" aria-hidden="true"></div>
        <div class="absolute inset-0 flex items-end gap-[2px]" aria-hidden="true">
          <div v-for="(p, i) in series" :key="p.date" class="flex h-full min-w-0 flex-1 items-end">
            <div
              v-if="p.value"
              :class="['w-full rounded-t-[3px] bg-accent transition-opacity', hover !== null && hover !== i ? 'opacity-50' : '']"
              :style="{ height: `${pct(p.value)}%` }"
            ></div>
          </div>
        </div>
        <div v-if="hover !== null" class="pointer-events-none absolute inset-y-0 w-px bg-line-strong" :style="{ left: `${left(hover)}%` }" aria-hidden="true"></div>
        <div
          v-if="tip" role="tooltip"
          class="pointer-events-none absolute -top-3 z-10 -translate-x-1/2 -translate-y-full rounded-sm border border-line bg-surface px-2 py-1 text-xs whitespace-nowrap"
          :style="{ left: `${Math.min(Math.max(left(hover), 8), 92)}%` }"
        >
          <span class="text-muted">{{ fmt(tip.date, true) }}</span> · <strong class="num text-fg">{{ format(tip.value) }}</strong>
        </div>
      </div>
    </div>
    <div class="relative h-4 font-mono text-[11px] text-muted" aria-hidden="true">
      <span
        v-for="t in ticks" :key="t" class="absolute top-0"
        :class="t === 0 ? 'left-0' : t === series.length - 1 ? 'right-0' : '-translate-x-1/2'"
        :style="t !== 0 && t !== series.length - 1 ? { left: `${left(t)}%` } : undefined"
      >{{ fmt(series[t].date) }}</span>
    </div>
    <table class="sr-only">
      <caption>{{ label }}</caption>
      <thead><tr><th>Tanggal</th><th>Jumlah</th></tr></thead>
      <tbody><tr v-for="p in series" :key="p.date"><td>{{ p.date }}</td><td>{{ p.value }}</td></tr></tbody>
    </table>
  </div>
</template>
