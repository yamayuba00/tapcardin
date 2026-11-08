<script setup>
// Waktu respons 24 jam satu monitor: garis 2px (aksen), titik bahaya di dasar untuk cek yang gagal, grid samar,
// crosshair + tooltip saat hover / sentuh. Satu seri → tanpa legenda (judul panel menamainya).
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps({
  /** @type {{at: string, ms: ?number, status: string, code: ?number, error: ?string}[]} */
  checks: { type: Array, required: true },
  interval: { type: Number, default: 60 }, // detik antar-cek: jeda jauh lebih lama = garis diputus
})
const H = 180
const PAD = { top: 12, right: 12, bottom: 24, left: 68 }
const wrap = ref(null)
const width = ref(600)
let ro = null
onMounted(() => {
  ro = new ResizeObserver(([e]) => (width.value = Math.max(240, Math.round(e.contentRect.width))))
  ro.observe(wrap.value)
})
onBeforeUnmount(() => ro?.disconnect())

const time = (iso) => new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit' }).format(new Date(iso))
const pts = computed(() => props.checks.map((c) => ({ ...c, t: new Date(c.at).getTime() })))
const span = computed(() => {
  const ts = pts.value.map((p) => p.t)
  const max = ts.length ? Math.max(...ts) : Date.now()
  return { min: ts.length ? Math.min(...ts) : max - 3600e3, max: Math.max(max, (ts[0] ?? max) + 60e3) }
})
const yMax = computed(() => {
  const m = Math.max(100, ...pts.value.map((p) => p.ms ?? 0))
  const step = 10 ** Math.floor(Math.log10(m))
  return Math.ceil(m / step) * step
})
const x = (t) => PAD.left + ((t - span.value.min) / (span.value.max - span.value.min)) * (width.value - PAD.left - PAD.right)
const y = (ms) => PAD.top + (1 - ms / yMax.value) * (H - PAD.top - PAD.bottom)
const base = H - PAD.bottom

/**
 * Ruas garis: putus di cek yang gagal (tanpa waktu respons) dan di jeda tanpa data (> 3× interval, mis. monitor dijeda).
 * Ruas berisi satu titik saja digambar sebagai dot agar tidak hilang.
 */
const segments = computed(() => {
  const gap = Math.max(props.interval * 3, 300) * 1000
  const out = []
  let cur = null
  let prev = null
  for (const p of pts.value) {
    const ok = p.ms != null && p.status !== 'down'
    if (!ok || (prev !== null && p.t - prev > gap)) cur = null
    if (ok) {
      if (!cur) out.push((cur = []))
      cur.push(p)
    }
    prev = p.t
  }
  return out
})
const path = computed(() => segments.value.filter((s) => s.length > 1)
  .map((s) => s.map((p, i) => `${i ? 'L' : 'M'}${x(p.t).toFixed(1)},${y(p.ms).toFixed(1)}`).join('')).join(''))
const dots = computed(() => segments.value.filter((s) => s.length === 1).map((s) => s[0]))
const fmtMs = (v) => `${v.toLocaleString('id-ID')} ms`
const ticks = computed(() => [0, 0.5, 1].map((f) => Math.round(yMax.value * f)))
const xTicks = computed(() => {
  const n = width.value < 420 ? 3 : 5
  return Array.from({ length: n }, (_, i) => span.value.min + ((span.value.max - span.value.min) * i) / (n - 1))
})

const hover = ref(null)
function move(e) {
  const rect = wrap.value.getBoundingClientRect()
  const px = (e.touches?.[0]?.clientX ?? e.clientX) - rect.left
  let best = null
  for (const p of pts.value) if (!best || Math.abs(x(p.t) - px) < Math.abs(x(best.t) - px)) best = p
  hover.value = best
}
</script>

<template>
  <div ref="wrap" class="relative w-full select-none" @mousemove="move" @touchstart.passive="move" @touchmove.passive="move" @mouseleave="hover = null">
    <p v-if="!checks.length" class="grid h-[180px] place-items-center text-sm text-muted">Belum ada data 24 jam terakhir.</p>
    <svg v-else :width="width" :height="H" role="img" :aria-label="`Waktu respons ${checks.length} cek terakhir dalam 24 jam`">
      <g class="text-[11px]">
        <g v-for="t in ticks" :key="t">
          <line :x1="PAD.left" :x2="width - PAD.right" :y1="y(t)" :y2="y(t)" class="stroke-line" stroke-width="1" />
          <text :x="PAD.left - 8" :y="y(t) + 4" text-anchor="end" class="fill-muted">{{ fmtMs(t) }}</text>
        </g>
        <text v-for="(t, i) in xTicks" :key="t" :x="x(t)" :y="H - 6" :text-anchor="i === 0 ? 'start' : i === xTicks.length - 1 ? 'end' : 'middle'" class="fill-muted">{{ time(new Date(t).toISOString()) }}</text>
      </g>
      <path :d="path" fill="none" class="stroke-accent" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
      <circle v-for="p in dots" :key="`d${p.at}`" :cx="x(p.t)" :cy="y(p.ms)" r="2.5" class="fill-accent" />
      <!-- cek gagal: penanda di dasar grafik (warna + posisi + tooltip "Gagal") -->
      <circle v-for="p in pts.filter((q) => q.status === 'down')" :key="p.at" :cx="x(p.t)" :cy="base" r="4" class="fill-danger stroke-surface" stroke-width="2" />
      <g v-if="hover">
        <line :x1="x(hover.t)" :x2="x(hover.t)" :y1="PAD.top" :y2="base" class="stroke-line-strong" stroke-width="1" />
        <circle v-if="hover.ms != null && hover.status !== 'down'" :cx="x(hover.t)" :cy="y(hover.ms)" r="4" class="fill-accent stroke-surface" stroke-width="2" />
      </g>
    </svg>
    <div
      v-if="hover"
      class="pointer-events-none absolute top-0 z-10 rounded-md border border-line bg-surface px-3 py-2 text-xs text-fg"
      :style="{ left: `${Math.min(Math.max(x(hover.t) + 12, 0), width - 170)}px` }"
    >
      <p class="font-mono text-muted">{{ time(hover.at) }} WIB</p>
      <p class="font-semibold">{{ hover.status === 'down' ? 'Gagal' : fmtMs(hover.ms) }}<span v-if="hover.code" class="font-normal text-muted"> · HTTP {{ hover.code }}</span></p>
      <p v-if="hover.error" class="max-w-40 text-muted">{{ hover.error }}</p>
    </div>
  </div>
</template>
