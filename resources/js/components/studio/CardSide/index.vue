<script setup>
// Render satu sisi kartu / satu pola lanyard dari JSON desain (BSD §5) sebagai SVG bersatuan mm (viewBox termasuk bleed).
// Mode edit: overlay DBD §3.5 (bleed, safe zone, chip, slot lanyard / tepi tali & batas pola), grid, seleksi banyak
// (klik + Shift/Ctrl, kotak seleksi), grup, geser dengan snap (Alt = bebas), ubah ukuran (Shift = rasio tetap),
// putar (Shift = kelipatan 15°). Elemen sistem & elemen yang dikunci tidak bisa digeser.
import { computed, onBeforeUnmount, onMounted, onUpdated, ref, useId } from 'vue'
import { Lock, Nfc } from 'lucide-vue-next'
import {
  BLEED, angleTo, center, expandGroups, isFixed, isSystem, layerBox, moveLayer, polygonPoints, ptToMm, rawBox, resizeLayer,
  autoFitWidth, resolveColor, snapMove, textLines, textSize, unionBox,
} from '@/lib/design'
import { glyph } from '@/lib/glyphs'

const props = defineProps({
  side: { type: Object, required: true },
  vars: { type: Object, default: () => ({}) },
  kit: { type: Object, default: null },
  qr: { type: Object, default: null }, // {modules, path}
  shortCode: { type: String, default: 'XXXX-XXXX' },
  editable: Boolean,
  overlays: { type: Boolean, default: true },
  selection: { type: Array, default: () => [] },
  grid: { type: Number, default: 0 }, // mm, 0 = mati
  snap: { type: Boolean, default: true },
  trim: Boolean, // tampilkan tepat ukuran potong (tanpa bleed) — mis. pola lanyard yang diulang
  sample: Boolean, // pratinjau galeri: slot logo kosong ditandai "LOGO" (tidak pernah dipakai untuk cetak)
})
const emit = defineEmits(['select', 'change', 'commit'])

const uid = useId()
const svg = ref(null)
const W = computed(() => props.side.size.w)
const H = computed(() => props.side.size.h)
const B = computed(() => props.side.size.bleed ?? BLEED)
const isLanyard = computed(() => !!props.side.zones?.repeat)
const viewBox = computed(() => (props.trim ? `0 0 ${W.value} ${H.value}` : `${-B.value} ${-B.value} ${W.value + 2 * B.value} ${H.value + 2 * B.value}`))
const zones = computed(() => props.side.zones ?? {})
const safe = computed(() => zones.value.safe ?? 4)
const visible = computed(() => props.side.layers.filter((l) => !l.hidden))
const selectedLayers = computed(() => props.side.layers.filter((l) => props.selection.includes(l.id)))
const single = computed(() => (selectedLayers.value.length === 1 ? selectedLayers.value[0] : null))
const selBox = computed(() => (selectedLayers.value.length ? unionBox(selectedLayers.value, props.vars) : null))

const col = (v, fb = 'currentColor') => resolveColor(v, props.kit, fb)
const bg = computed(() => col(props.side.background?.value, 'transparent'))
const bgImage = computed(() => (props.side.background?.type === 'image' ? props.side.background : null))
const imgUrl = (l) => (l.asset === 'kit:logo' ? props.kit?.logo?.url : l.url)
// tinta kontras dengan latar = warna elemen sistem (NFC / merek), dipakai untuk penanda slot logo di galeri
const sysInk = computed(() => props.side.layers.find((l) => l.locked && l.color)?.color ?? 'currentColor')
/**
 * Lebar maksimum teks berisi data ({{nama}}, {{jabatan}}, …) agar isi yang panjang tidak keluar kartu: fitWidth bila
 * diatur, selain itu sisa ruang sampai zona aman sesuai perataan. Teks tetap/berputar/sengaja keluar tepi tidak diubah.
 */
const autoFit = (l) => (isLanyard.value ? l.fitWidth ?? null : autoFitWidth(l, { w: W.value }, safe.value))
const fitted = (l) => {
  const f = autoFit(l)
  return f && f !== l.fitWidth ? { ...l, fitWidth: f } : l
}
const fontMm = (l) => ptToMm(textSize(fitted(l), props.vars))

/**
 * Pengaman terakhir setelah font asli dimuat: lebar huruf sebenarnya bisa lebih besar dari perkiraan, jadi teks yang
 * masih melewati batas dirapatkan tepat ke batas (textLength). Ikut terbawa ke ekspor PNG/PDF karena memakai SVG ini.
 */
function clampText() {
  svg.value?.querySelectorAll('text[data-fit]').forEach((el) => {
    el.removeAttribute('textLength')
    el.removeAttribute('lengthAdjust')
    const max = Number(el.dataset.fit)
    let len = 0
    try { len = el.getComputedTextLength() } catch { return }
    if (max > 0 && len > max) {
      el.setAttribute('textLength', String(max))
      el.setAttribute('lengthAdjust', 'spacingAndGlyphs')
    }
  })
}
onMounted(() => {
  clampText()
  document.fonts?.ready.then(clampText)
})
onUpdated(clampText)
const anchor = (a) => ({ center: 'middle', right: 'end' })[a] ?? 'start'
const rings = (l) => {
  const out = []
  const n = Math.max(1, Math.floor((l.to - l.from) / l.step) + 1)
  for (let i = 0; i < n; i++) out.push({ r: l.from + i * l.step, o: (l.opacity ?? 0.3) * (1 - i / (n + 1)) })
  return out
}
const transform = (l) => {
  if (!l.rotate) return undefined
  const c = center(l, props.vars)
  return `rotate(${l.rotate} ${c.x} ${c.y})`
}
const gridLines = computed(() => {
  if (!props.grid || !props.editable) return { x: [], y: [] }
  const xs = []
  const ys = []
  for (let v = props.grid; v < W.value; v += props.grid) xs.push(v)
  for (let v = props.grid; v < H.value; v += props.grid) ys.push(v)
  return { x: xs, y: ys }
})

// ── Skala layar: ukuran pegangan & ambang snap tetap dalam piksel di level zoom berapa pun ──
const pxPerMm = ref(6)
let ro = null
onMounted(() => {
  ro = new ResizeObserver(() => { if (svg.value) pxPerMm.value = svg.value.clientWidth / (W.value + (props.trim ? 0 : 2 * B.value)) || 6 })
  ro.observe(svg.value)
})
onBeforeUnmount(() => ro?.disconnect())
const px = (n) => n / pxPerMm.value

const handles = computed(() => {
  const l = single.value
  if (!props.editable || !l || isFixed(l)) return []
  if (l.type === 'line') return [{ id: 'p1', x: l.x1, y: l.y1 }, { id: 'p2', x: l.x2, y: l.y2 }]
  const b = rawBox(l, props.vars)
  if (!b) return []
  const pad = 0.6
  const x0 = b.x - pad, y0 = b.y - pad, x1 = b.x + b.w + pad, y1 = b.y + b.h + pad
  const c = center(l, props.vars)
  const r = ((l.rotate ?? 0) * Math.PI) / 180
  // pegangan ikut berputar bersama layer
  const rot = (x, y) => ({ x: c.x + (x - c.x) * Math.cos(r) - (y - c.y) * Math.sin(r), y: c.y + (x - c.x) * Math.sin(r) + (y - c.y) * Math.cos(r) })
  const corners = [
    { id: 'nw', ...rot(x0, y0), cursor: 'cursor-nwse-resize' }, { id: 'ne', ...rot(x1, y0), cursor: 'cursor-nesw-resize' },
    { id: 'sw', ...rot(x0, y1), cursor: 'cursor-nesw-resize' }, { id: 'se', ...rot(x1, y1), cursor: 'cursor-nwse-resize' },
  ]
  const canRotate = !['circle', 'rings'].includes(l.type)
  return canRotate ? [...corners, { id: 'rot', ...rot(c.x, y0 - px(22)), cursor: 'cursor-grab', round: true, stem: rot(c.x, y0) }] : corners
})
const outline = computed(() => {
  const l = single.value
  if (!l) return null
  const b = rawBox(l, props.vars)
  return b ? { ...b, transform: transform(l) } : null
})

// ── Interaksi pointer (mm), dihitung dari keadaan awal agar snap presisi ──
const guides = ref({ x: [], y: [] })
const marquee = ref(null)
let drag = null
function toMm(e) {
  const pt = svg.value.createSVGPoint()
  pt.x = e.clientX
  pt.y = e.clientY
  const p = pt.matrixTransform(svg.value.getScreenCTM().inverse())
  return { x: p.x, y: p.y }
}
function capture(e) {
  svg.value.setPointerCapture?.(e.pointerId)
}
function downLayer(e, layer) {
  if (!props.editable) return
  e.stopPropagation()
  const ids = expandGroups([layer.id], props.side.layers)
  if (e.shiftKey || e.metaKey || e.ctrlKey) {
    const has = props.selection.includes(layer.id)
    emit('select', has ? props.selection.filter((id) => !ids.includes(id)) : [...new Set([...props.selection, ...ids])])
    return
  }
  const sel = props.selection.includes(layer.id) ? props.selection : ids
  if (sel !== props.selection) emit('select', sel)
  const movable = props.side.layers.filter((l) => sel.includes(l.id) && !isFixed(l))
  if (!movable.length) return
  drag = { mode: 'move', origins: movable.map((l) => ({ ...l })), start: toMm(e), moved: false }
  capture(e)
}
function downHandle(e, handle) {
  e.stopPropagation()
  const l = single.value
  drag = { mode: handle === 'rot' ? 'rotate' : 'resize', handle, origins: [{ ...l }], start: toMm(e), moved: false, center: center(l, props.vars) }
  capture(e)
}
function downCanvas(e) {
  if (!props.editable) return
  const p = toMm(e)
  drag = { mode: 'marquee', start: p, additive: e.shiftKey || e.metaKey || e.ctrlKey, moved: false }
  capture(e)
}
function move(e) {
  if (!drag) return
  const p = toMm(e)
  const dx = p.x - drag.start.x
  const dy = p.y - drag.start.y
  if (!drag.moved && Math.abs(dx) + Math.abs(dy) < 0.3) return
  drag.moved = true

  if (drag.mode === 'marquee') {
    marquee.value = { x: Math.min(p.x, drag.start.x), y: Math.min(p.y, drag.start.y), w: Math.abs(dx), h: Math.abs(dy) }
    return
  }
  if (drag.mode === 'rotate') {
    const o = drag.origins[0]
    emit('change', [{ id: o.id, layer: { ...o, rotate: angleTo(drag.center, p, e.shiftKey) } }])
    return
  }
  if (drag.mode === 'resize') {
    const o = drag.origins[0]
    emit('change', [{ id: o.id, layer: resizeLayer(o, drag.handle, dx, dy, { keepAspect: e.shiftKey, vars: props.vars }) }])
    return
  }
  // geser banyak layer sekaligus; snap memakai kotak gabungan
  let sdx = dx
  let sdy = dy
  if (props.snap && !e.altKey) {
    const ids = drag.origins.map((o) => o.id)
    const others = props.side.layers.filter((l) => !ids.includes(l.id) && !l.hidden && l.type !== 'rings').map((l) => layerBox(l, props.vars)).filter(Boolean)
    const snap = snapMove(unionBox(drag.origins, props.vars), dx, dy, { size: props.side.size, others, threshold: px(6), safe: safe.value, grid: props.grid })
    sdx = snap.dx
    sdy = snap.dy
    guides.value = snap.guides
  } else {
    guides.value = { x: [], y: [] }
  }
  emit('change', drag.origins.map((o) => ({ id: o.id, layer: moveLayer(o, sdx, sdy) })))
}
function up() {
  if (drag?.mode === 'marquee') {
    if (drag.moved && marquee.value) {
      const m = marquee.value
      const hit = visible.value.filter((l) => !isSystem(l)).filter((l) => {
        const b = layerBox(l, props.vars)
        return b && b.x < m.x + m.w && m.x < b.x + b.w && b.y < m.y + m.h && m.y < b.y + b.h
      }).map((l) => l.id)
      const ids = expandGroups(hit, props.side.layers)
      emit('select', drag.additive ? [...new Set([...props.selection, ...ids])] : ids)
    } else if (!drag.additive) {
      emit('select', [])
    }
  } else if (drag?.moved) {
    emit('commit')
  }
  drag = null
  marquee.value = null
  guides.value = { x: [], y: [] }
}
</script>

<template>
  <svg
    ref="svg"
    :viewBox="viewBox"
    class="block h-auto w-full touch-none select-none" preserveAspectRatio="none"
    role="img"
    :aria-label="isLanyard ? 'Pratinjau pola lanyard' : 'Pratinjau sisi kartu'"
    @pointermove="move"
    @pointerup="up"
    @pointercancel="up"
    @pointerdown="downCanvas"
  >
    <defs>
      <clipPath :id="`bleed-${uid}`"><rect :x="-B" :y="-B" :width="W + 2 * B" :height="H + 2 * B" /></clipPath>
      <pattern :id="`hatch-${uid}`" width="1.2" height="1.2" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
        <line x1="0" y1="0" x2="0" y2="1.2" class="stroke-amber-300" stroke-width="0.25" />
      </pattern>
      <template v-for="l in visible" :key="`clip-${l.id}`">
        <clipPath v-if="l.type === 'image' && l.mask" :id="`m-${uid}-${l.id}`">
          <ellipse v-if="l.mask === 'circle'" :cx="l.x + l.w / 2" :cy="l.y + l.h / 2" :rx="l.w / 2" :ry="l.h / 2" />
          <rect v-else :x="l.x" :y="l.y" :width="l.w" :height="l.h" :rx="l.radius ?? 2" />
        </clipPath>
      </template>
    </defs>

    <g :clip-path="`url(#bleed-${uid})`">
      <rect :x="-B" :y="-B" :width="W + 2 * B" :height="H + 2 * B" :fill="bg" />
      <image v-if="bgImage" :href="bgImage.url ?? undefined" :x="-B" :y="-B" :width="W + 2 * B" :height="H + 2 * B" :preserveAspectRatio="bgImage.fit === 'contain' ? 'xMidYMid meet' : 'xMidYMid slice'" />

      <g
        v-for="l in visible" :key="l.id" :transform="transform(l)" :opacity="l.type === 'rings' ? undefined : l.opacity ?? undefined"
        :class="editable ? (isFixed(l) ? 'cursor-pointer' : 'cursor-move') : ''"
        @pointerdown="downLayer($event, l)"
      >
        <text
          v-if="l.type === 'text'"
          :x="l.x" :y="l.y" :fill="col(l.color)" :font-family="l.font" :font-weight="l.weight" :font-style="l.italic ? 'italic' : undefined"
          :font-size="fontMm(l)" :text-anchor="anchor(l.align)" :letter-spacing="(l.tracking ?? 0) * fontMm(l)" :data-fit="autoFit(l) ?? undefined"
        ><tspan v-for="(line, i) in textLines(l, vars)" :key="i" :x="l.x" :dy="i === 0 ? 0 : fontMm(l) * (l.lineHeight ?? 1.25)">{{ line || ' ' }}</tspan></text>
        <rect v-else-if="l.type === 'rect'" :x="l.x" :y="l.y" :width="l.w" :height="l.h" :rx="l.rx || 0" :fill="col(l.fill)" :stroke="l.stroke ? col(l.stroke) : undefined" :stroke-width="l.strokeWidth" />
        <ellipse v-else-if="l.type === 'ellipse'" :cx="l.x + l.w / 2" :cy="l.y + l.h / 2" :rx="l.w / 2" :ry="l.h / 2" :fill="col(l.fill)" :stroke="l.stroke ? col(l.stroke) : undefined" :stroke-width="l.strokeWidth" />
        <polygon v-else-if="l.type === 'polygon'" :points="polygonPoints(l)" :fill="col(l.fill)" :stroke="l.stroke ? col(l.stroke) : undefined" :stroke-width="l.strokeWidth" stroke-linejoin="round" />
        <line v-else-if="l.type === 'line'" :x1="l.x1" :y1="l.y1" :x2="l.x2" :y2="l.y2" :stroke="col(l.color)" :stroke-width="l.width" stroke-linecap="round" :stroke-dasharray="l.dash ? `${l.width * 3} ${l.width * 2}` : undefined" />
        <circle v-else-if="l.type === 'circle'" :cx="l.cx" :cy="l.cy" :r="l.r" fill="none" :stroke="col(l.stroke)" :stroke-width="l.strokeWidth" />
        <g v-else-if="l.type === 'rings'">
          <circle v-for="c in rings(l)" :key="c.r" :cx="l.cx" :cy="l.cy" :r="c.r" fill="none" :stroke="col(l.color)" stroke-width="0.25" :stroke-opacity="c.o" />
        </g>
        <Nfc v-else-if="l.type === 'icon'" :x="l.x" :y="l.y" :size="l.size" :color="l.color" :stroke-width="1.6" />
        <component :is="glyph(l.name)" v-else-if="l.type === 'glyph'" :x="l.x" :y="l.y" :size="l.size" :color="col(l.color)" :stroke-width="l.strokeWidth ?? 2" />
        <g v-else-if="l.type === 'image'" :clip-path="l.mask ? `url(#m-${uid}-${l.id})` : undefined">
          <image v-if="imgUrl(l)" :href="imgUrl(l)" :x="l.x" :y="l.y" :width="l.w" :height="l.h" :preserveAspectRatio="l.fit === 'cover' ? 'xMidYMid slice' : 'xMidYMid meet'" />
          <rect v-else-if="editable" :x="l.x" :y="l.y" :width="l.w" :height="l.h" fill="none" class="stroke-muted" stroke-width="0.2" stroke-dasharray="1 0.8" />
          <g v-else-if="sample && l.asset === 'kit:logo'" :fill="sysInk" :stroke="sysInk" opacity="0.55">
            <rect :x="l.x" :y="l.y" :width="l.w" :height="l.h" :rx="l.mask === 'circle' ? Math.min(l.w, l.h) / 2 : 1" fill="none" stroke-width="0.25" stroke-dasharray="1 0.7" />
            <text :x="l.x + l.w / 2" :y="l.y + l.h / 2" :font-size="Math.min(l.h * 0.32, l.w * 0.2)" text-anchor="middle" dominant-baseline="central" stroke="none" font-family="IBM Plex Mono" font-weight="500" letter-spacing="0.3">LOGO</text>
          </g>
        </g>
        <g v-else-if="l.type === 'qr'">
          <rect :x="l.x" :y="l.y" :width="l.size" :height="l.size" :rx="l.rx || 0" :fill="l.bg" />
          <svg v-if="qr" :x="l.x + 1.5" :y="l.y + 1.5" :width="l.size - 3" :height="l.size - 3" :viewBox="`0 0 ${qr.modules} ${qr.modules}`" shape-rendering="crispEdges">
            <path :d="qr.path" :fill="l.fg" />
          </svg>
        </g>
        <text v-else-if="l.type === 'shortcode'" :x="l.x" :y="l.y" :fill="l.color" font-family="IBM Plex Mono" font-weight="500"
          :font-size="ptToMm(l.size)" :text-anchor="anchor(l.align)" :letter-spacing="0.12 * ptToMm(l.size)">TC·{{ shortCode }}</text>
        <g v-else-if="l.type === 'brand'" :transform="`translate(${l.align === 'center' ? l.x - ptToMm(l.size) * 2.6 : l.x} ${l.y - ptToMm(l.size) * 0.95})`">
          <svg :width="ptToMm(l.size) * 1.15" :height="ptToMm(l.size) * 1.15" viewBox="0 0 24 24" fill="none" :stroke="l.color" stroke-width="1.8" stroke-linecap="round">
            <rect x="2.75" y="2.75" width="18.5" height="18.5" rx="2" /><path d="M6.5 9.5h7M10 9.5v8" />
            <path d="M15.5 8.2a1.6 1.6 0 0 1 1.3 1.3" /><path d="M15.5 6.4a3.4 3.4 0 0 1 3.1 3.1" /><path d="M15.5 4.6a5.2 5.2 0 0 1 4.9 4.9" />
          </svg>
          <text :x="ptToMm(l.size) * 1.45" :y="ptToMm(l.size) * 0.95" :fill="l.color" font-family="Syne" font-weight="700" :font-size="ptToMm(l.size)">TapCard</text>
        </g>
      </g>
    </g>

    <!-- Lubang slot lanyard (bentuk fisik kartu, selalu ditampilkan) -->
    <rect v-if="zones.slot" :x="zones.slot.x + 1.25" y="2.2" :width="zones.slot.w - 2.5" height="3.2" rx="1.6" class="fill-bg stroke-line-strong" stroke-width="0.2" />

    <!-- Overlay editor (DBD §3.5): tidak ikut tercetak -->
    <g v-if="editable && overlays" class="pointer-events-none">
      <g v-if="grid" class="stroke-line" :stroke-width="px(1)">
        <line v-for="x in gridLines.x" :key="`gx${x}`" :x1="x" :x2="x" :y1="0" :y2="H" />
        <line v-for="y in gridLines.y" :key="`gy${y}`" :y1="y" :y2="y" :x1="0" :x2="W" />
      </g>
      <path
        :d="`M${-B} ${-B}h${W + 2 * B}v${H + 2 * B}h${-(W + 2 * B)}z M0 0v${H}h${W}v${-H}z`"
        fill-rule="evenodd" class="fill-coral-300/15"
      />
      <template v-if="isLanyard">
        <!-- tepi tali + margin aman; garis putus vertikal = batas pola yang berulang -->
        <line x1="0" :x2="W" :y1="safe" :y2="safe" class="stroke-sky-300" stroke-width="0.15" stroke-dasharray="1 0.8" />
        <line x1="0" :x2="W" :y1="H - safe" :y2="H - safe" class="stroke-sky-300" stroke-width="0.15" stroke-dasharray="1 0.8" />
        <line x1="0" x2="0" y1="0" :y2="H" class="stroke-coral-300" stroke-width="0.25" stroke-dasharray="1.5 1" />
        <line :x1="W" :x2="W" y1="0" :y2="H" class="stroke-coral-300" stroke-width="0.25" stroke-dasharray="1.5 1" />
      </template>
      <template v-else>
        <rect x="0" y="0" :width="W" :height="H" rx="3.18" fill="none" class="stroke-line-strong" stroke-width="0.2" />
        <rect :x="safe" :y="safe" :width="W - 2 * safe" :height="H - 2 * safe" fill="none" class="stroke-sky-300" stroke-width="0.2" stroke-dasharray="1 0.8" />
        <rect v-if="zones.chip" :x="zones.chip.x" :y="zones.chip.y" :width="zones.chip.w" :height="zones.chip.h" :fill="`url(#hatch-${uid})`" fill-opacity="0.35" />
        <rect v-if="zones.slot" :x="zones.slot.x" :y="zones.slot.y" :width="zones.slot.w" :height="zones.slot.h" class="fill-coral-300/20 stroke-coral-300" stroke-width="0.25" stroke-dasharray="0.8 0.6" />
      </template>

      <!-- seleksi -->
      <template v-if="selectedLayers.length > 1 && selBox">
        <rect v-for="l in selectedLayers" :key="`s${l.id}`" v-bind="(({ x, y, w, h }) => ({ x, y, width: w, height: h }))(layerBox(l, vars) ?? { x: 0, y: 0, w: 0, h: 0 })" fill="none" class="stroke-accent" :stroke-width="px(1)" stroke-dasharray="0.6 0.4" />
        <rect :x="selBox.x - 0.6" :y="selBox.y - 0.6" :width="selBox.w + 1.2" :height="selBox.h + 1.2" fill="none" class="stroke-accent" :stroke-width="px(1.5)" />
      </template>
      <rect
        v-else-if="outline" :x="outline.x - 0.6" :y="outline.y - 0.6" :width="outline.w + 1.2" :height="outline.h + 1.2" :transform="outline.transform"
        fill="none" class="stroke-accent" :stroke-width="px(1.5)" :stroke-dasharray="isFixed(single) ? '0.8 0.6' : undefined"
      />
      <Lock v-if="single && isFixed(single) && outline" :x="outline.x + outline.w - 1" :y="outline.y - 3.6" :size="3" class="text-accent" />

      <!-- garis panduan snap -->
      <line v-for="gx in guides.x" :key="`gu-x${gx}`" :x1="gx" :x2="gx" :y1="-B" :y2="H + B" class="stroke-accent" :stroke-width="px(1)" />
      <line v-for="gy in guides.y" :key="`gu-y${gy}`" :y1="gy" :y2="gy" :x1="-B" :x2="W + B" class="stroke-accent" :stroke-width="px(1)" />
      <rect v-if="marquee" :x="marquee.x" :y="marquee.y" :width="marquee.w" :height="marquee.h" class="fill-accent/10 stroke-accent" :stroke-width="px(1)" stroke-dasharray="1 0.6" />
    </g>

    <!-- pegangan ubah ukuran & putar -->
    <g v-if="handles.length && !guides.x.length && !guides.y.length && !marquee">
      <template v-for="h in handles" :key="h.id">
        <line v-if="h.stem" :x1="h.stem.x" :y1="h.stem.y" :x2="h.x" :y2="h.y" class="stroke-accent pointer-events-none" :stroke-width="px(1)" />
        <rect
          :x="h.x - px(9) / 2" :y="h.y - px(9) / 2" :width="px(9)" :height="px(9)" :rx="h.round || h.id.startsWith('p') ? px(9) / 2 : 0"
          :class="['fill-surface stroke-accent', h.cursor ?? 'cursor-move']" :stroke-width="px(1.5)"
          @pointerdown="downHandle($event, h.id)"
        />
      </template>
    </g>
  </svg>
</template>
