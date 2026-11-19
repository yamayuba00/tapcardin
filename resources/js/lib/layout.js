// Tata letak presisi Studio: ratakan/sebar terhadap seleksi, kanvas, atau area aman; ulangi berpola (baris × kolom);
// garis bantu. Murni (tanpa DOM) agar bisa diuji `node --test`.
import { layerBox, moveLayer, newId, round, unionBox } from './design.js'

/** Geser halus/kasar dengan panah (mm). */
export const NUDGE = { fine: 0.1, coarse: 1 }
export const MAX_GUIDES = 20
/** Lebar penggaris editor (mm, di luar area bleed). */
export const RULER_MM = 6

/**
 * Kotak acuan perataan. 'selection' = gabungan seleksi (1 elemen → kanvas), 'canvas' = ukuran potong, 'safe' = area aman.
 * @param {'selection'|'canvas'|'safe'} to
 */
export function alignRef(layers, to, size, safe, vars = {}) {
  if (to === 'safe') return { x: safe, y: safe, w: size.w - 2 * safe, h: size.h - 2 * safe }
  if (to === 'canvas' || layers.length < 2) return { x: 0, y: 0, w: size.w, h: size.h }
  return unionBox(layers, vars)
}

/** Ratakan tiap layer ke tepi/tengah kotak acuan. @param {'left'|'hcenter'|'right'|'top'|'vmiddle'|'bottom'} how */
export function alignTo(layers, how, ref, vars = {}) {
  return layers.map((l) => {
    const b = layerBox(l, vars)
    if (!b) return l
    const dx = { left: ref.x - b.x, hcenter: ref.x + ref.w / 2 - (b.x + b.w / 2), right: ref.x + ref.w - (b.x + b.w) }[how] ?? 0
    const dy = { top: ref.y - b.y, vmiddle: ref.y + ref.h / 2 - (b.y + b.h / 2), bottom: ref.y + ref.h - (b.y + b.h) }[how] ?? 0
    return moveLayer(l, round(dx, 3), round(dy, 3))
  })
}

/**
 * Sebar dengan jarak sama di dalam kotak acuan: elemen pertama menempel tepi awal, terakhir tepi akhir (≥ 2 elemen).
 * Acuan = gabungan seleksi → sama dengan distribusi biasa (butuh ≥ 3 agar ada yang bergeser).
 * @param {'h'|'v'} axis
 */
export function distributeIn(layers, axis, ref, vars = {}) {
  if (layers.length < 2) return layers
  const [pos, len] = axis === 'h' ? ['x', 'w'] : ['y', 'h']
  const items = layers.map((l, i) => ({ l, i, b: layerBox(l, vars) })).filter((o) => o.b).sort((a, z) => a.b[pos] - z.b[pos])
  const total = items.reduce((s, o) => s + o.b[len], 0)
  const gap = (ref[len] - total) / (items.length - 1)
  const out = [...layers]
  let at = ref[pos]
  for (const o of items) {
    const d = round(at - o.b[pos], 3)
    out[o.i] = axis === 'h' ? moveLayer(o.l, d, 0) : moveLayer(o.l, 0, d)
    at += o.b[len] + gap
  }
  return out
}

/**
 * Ulangi berpola: salin seleksi menjadi `rows` × `cols` (sel pertama = aslinya), jarak `gapX`/`gapY` mm di antara
 * salinan. Semua salinan + aslinya dijadikan satu grup. Dibatasi `max` layer baru.
 * @returns {{copies: object[], group: string, truncated: boolean}}
 */
export function stepRepeat(layers, { rows = 1, cols = 2, gapX = 2, gapY = 2 } = {}, existing = [], vars = {}, max = 79) {
  const box = unionBox(layers, vars)
  if (!box) return { copies: [], group: null, truncated: false }
  const taken = [...existing]
  const group = `g${newId(taken)}`
  const copies = []
  let truncated = false
  for (let r = 0; r < rows; r++) {
    for (let c = 0; c < cols; c++) {
      if (r === 0 && c === 0) continue
      if (copies.length + layers.length > max) { truncated = true; break }
      const dx = round(c * (box.w + gapX), 3)
      const dy = round(r * (box.h + gapY), 3)
      for (const l of layers) {
        const id = newId(taken)
        taken.push({ id })
        copies.push({ ...moveLayer(l, dx, dy), id, group })
      }
    }
  }
  return { copies, group, truncated }
}

/** Garis bantu: tambah/pindah/hapus nilai mm (dibulatkan 0,1 mm, unik, terurut, maks 20 per sumbu). */
export function setGuide(list = [], from, to) {
  const out = list.filter((v) => v !== from)
  if (to != null) out.push(round(to, 1))
  return [...new Set(out)].sort((a, b) => a - b).slice(0, MAX_GUIDES)
}
