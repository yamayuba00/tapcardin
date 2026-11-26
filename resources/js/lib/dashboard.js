// Helper beranda (grafik, pembanding, tanggal pendek) — murni, diuji dengan `node --test`.
import { delta } from './format.js'

/** Ambil n titik terakhir dari deret harian (rentang grafik 7 hari / 30 hari). */
export const lastDays = (series = [], n) => series.slice(-Math.max(1, n))

/** Petakan nilai ke koordinat SVG (viewBox width×height); nilai 0 di bawah, maksimum di atas. */
export function chartPoints(values, { width = 600, height = 220, pad = 12 } = {}) {
  const max = Math.max(1, ...values)
  const n = values.length
  const step = n > 1 ? width / (n - 1) : 0
  return values.map((v, i) => ({
    x: n > 1 ? +(i * step).toFixed(2) : width / 2,
    y: +(height - pad - ((height - pad * 2) * v) / max).toFixed(2),
    value: v,
  }))
}

/** Indeks titik paling dekat dengan posisi x (-1 jika kosong). */
export function nearestIndex(points, x) {
  let best = -1
  let bestDist = Infinity
  points.forEach((p, i) => {
    const d = Math.abs(p.x - x)
    if (d < bestDist) { best = i; bestDist = d }
  })
  return best
}

/** Pil delta: { value: persen absolut, up } atau null jika pembanding 0 (tidak ada yang dibandingkan). */
export function deltaPill(current, previous) {
  const d = delta(current, previous)
  return d === null ? null : { value: Math.abs(d), up: d >= 0 }
}

/** "2026-10-24" → "24 Okt" (tanggal kalender, tanpa geser zona waktu). */
export function shortDay(iso) {
  if (!iso) return ''
  return new Intl.DateTimeFormat('id-ID', { timeZone: 'UTC', day: 'numeric', month: 'short' }).format(new Date(`${iso}T00:00:00Z`))
}
