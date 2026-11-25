// Pustaka motif bawaan (karya sendiri, bukan aset pihak ketiga). Tiap motif = kumpulan layer biasa (rect, polygon,
// ellipse, line, circle) yang dibuat dalam ruang 0–100, lalu di-fit ke kotak tujuan dengan rasio tetap.
// Warna hanya lewat nama palet (lihat App\Support\Design\Palette); tidak ada hex di sini.
// Semua geometri tetap di dalam 0–100 (tanpa rotasi), jadi batas kotak selalu terjaga setelah di-fit.
import { createLayer, newId } from './design.js'

const pick = (pal, name) => pal.find((c) => c.name === name)?.hex ?? pal[0]?.hex ?? ''

const shape = (type, color, extra) => ({ type, color, ...extra })

const MOTIFS = [
  { key: 'diagonal', name: 'Garis diagonal', colors: ['onyx-950', 'champagne-500'], build: (c) => [0, 1, 2, 3, 4].map((i) => shape('line', c[i % 2], { x1: 10 + i * 18, y1: 0, x2: 26 + i * 18, y2: 100, width: 4 })) },
  { key: 'chevron', name: 'Chevron', colors: ['champagne-500'], build: (c) => [0, 1, 2].flatMap((i) => [
    shape('line', c[0], { x1: 0, y1: 20 + i * 30, x2: 50, y2: 40 + i * 30, width: 3 }),
    shape('line', c[0], { x1: 50, y1: 40 + i * 30, x2: 100, y2: 20 + i * 30, width: 3 }),
  ]) },
  { key: 'gelombang', name: 'Pita gelombang', colors: ['sky-700', 'bone-200'], build: (c) => [0, 1, 2].map((i) => shape('rect', i % 2 ? c[1] : c[0], { x: 0, y: 20 + i * 22, w: 100, h: 8, rx: 4 })) },
  { key: 'serpihan', name: 'Serpihan segitiga', colors: ['coral-300', 'navy-800'], build: (c) => [[0, 0, 40], [50, 30, 50], [10, 60, 40]].map(([x, y, w], i) => shape('polygon', i % 2 ? c[1] : c[0], { x, y, w, h: w, sides: 3, star: false, inner: 0.5, rotate: 0 })) },
  { key: 'sarang', name: 'Sarang heksagon', colors: ['champagne-500', 'onyx-800'], build: (c) => [0, 1, 2, 3].flatMap((r) => [0, 1, 2].map((q) => shape('polygon', (r + q) % 2 ? c[1] : c[0], { x: q * 30 + (r % 2) * 15, y: r * 26, w: 22, h: 22, sides: 6, star: false, inner: 0.5, rotate: 0 }))) },
  { key: 'titik', name: 'Grid titik', colors: ['champagne-500'], build: (c) => [0, 1, 2, 3, 4].flatMap((r) => [0, 1, 2, 3, 4, 5].map((q) => shape('ellipse', c[0], { x: q * 18, y: r * 18, w: 4, h: 4 }))) },
  { key: 'pita-miring', name: 'Pita miring', colors: ['onyx-950', 'sage-700'], build: (c) => [shape('line', c[0], { x1: 20, y1: 0, x2: 70, y2: 100, width: 14 }), shape('line', c[1], { x1: 50, y1: 0, x2: 100, y2: 100, width: 3 })] },
  { key: 'sudut', name: 'Siku sudut', colors: ['bone-50'], build: (c) => [
    shape('line', c[0], { x1: 0, y1: 0, x2: 22, y2: 0, width: 2 }), shape('line', c[0], { x1: 0, y1: 0, x2: 0, y2: 22, width: 2 }),
    shape('line', c[0], { x1: 100, y1: 100, x2: 78, y2: 100, width: 2 }), shape('line', c[0], { x1: 100, y1: 100, x2: 100, y2: 78, width: 2 }),
  ] },
  { key: 'lingkar', name: 'Lingkar konsentris', colors: ['sky-700'], build: (c) => [10, 20, 30, 40].map((r) => shape('circle', c[0], { cx: 50, cy: 50, r, stroke: c[0], strokeWidth: 0.6 })) },
  { key: 'batang', name: 'Batang bertingkat', colors: ['champagne-500', 'bronze-700'], build: (c) => [0, 1, 2, 3].map((i) => shape('rect', i % 2 ? c[1] : c[0], { x: 5, y: 10 + i * 22, w: 90 - i * 18, h: 10, rx: 0 })) },
  { key: 'blok-miring', name: 'Blok miring', colors: ['umber-600', 'bone-100'], build: (c) => [shape('polygon', c[0], { x: 10, y: 20, w: 80, h: 60, sides: 4, star: false, inner: 0.5, rotate: 0 })] },
  { key: 'sinar', name: 'Garis sinar', colors: ['ink-950'], build: (c) => Array.from({ length: 12 }, (_, i) => {
    const a = (i * Math.PI) / 6
    return shape('line', c[0], { x1: 50 + Math.cos(a) * 8, y1: 50 + Math.sin(a) * 8, x2: 50 + Math.cos(a) * 48, y2: 50 + Math.sin(a) * 48, width: 0.8 })
  }) },
]

/** Layer motif di kotak tujuan {x,y,w,h} (mm). Rasio dijaga: skala seragam, dipusatkan di kotak. */
export function fitMotif(layers, box) {
  const s = Math.min(box.w, box.h) / 100
  const ox = box.x + (box.w - 100 * s) / 2
  const oy = box.y + (box.h - 100 * s) / 2
  const X = (v) => ox + v * s
  const Y = (v) => oy + v * s
  return layers.map((l) => {
    if (l.type === 'line') return { ...l, x1: X(l.x1), y1: Y(l.y1), x2: X(l.x2), y2: Y(l.y2), width: (l.width ?? 1) * s }
    if (l.type === 'circle') return { ...l, cx: X(l.cx), cy: Y(l.cy), r: l.r * s, strokeWidth: (l.strokeWidth ?? 1) * s }
    return { ...l, x: X(l.x), y: Y(l.y), w: (l.w ?? 0) * s, h: (l.h ?? 0) * s, rx: l.rx ? l.rx * s : l.rx }
  })
}

/** Bangun layer siap sisip: pakai createLayer agar bentuk & properti sama dengan layer biasa. */
export function motifLayers(key, box, palette, idSource = []) {
  const m = MOTIFS.find((x) => x.key === key)
  if (!m) return []
  const colors = m.colors.map((n) => pick(palette, n))
  const templates = m.build(colors)
  const fitted = fitMotif(templates, box)
  const ids = [...idSource]
  return fitted.map((t) => {
    const id = newId(ids)
    ids.push({ id })
    const { type, color, ...rest } = t
    return { id, ...createLayer(type, { w: box.w, h: box.h }, color, rest) }
  })
}

export { MOTIFS }
