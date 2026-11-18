// Koleksi font desain cetak (OFL, disajikan dari /fonts). Sumber tunggal: fonts.json — juga dibaca server
// (App\Support\Design\Fonts). Tiga font dasar dimuat global lewat app.css; sisanya dimuat sesuai kebutuhan
// (hanya halaman yang merender desain), jadi tidak menambah bundle aplikasi.
import LIST from './fonts.json' with { type: 'json' }

export const FONT_LIST = LIST
export const FONT_CATEGORIES = { sans: 'Sans', serif: 'Serif', display: 'Display', rounded: 'Membulat', script: 'Tulisan tangan', mono: 'Mono' }
const BY = Object.fromEntries(LIST.map((f) => [f.family, f]))
const FALLBACK = BY['IBM Plex Sans']

export const fontMeta = (family) => BY[family] ?? FALLBACK

/** Tebal tersedia terdekat untuk `weight`. */
export function nearestWeight(family, weight) {
  const ws = fontMeta(family).weights
  return ws.reduce((best, w) => (Math.abs(w - weight) < Math.abs(best - weight) ? w : best), ws[0])
}

export function fontUrl(family, weight = 400) {
  const f = BY[family]
  return f ? `/fonts/${f.slug}-${nearestWeight(family, weight)}.woff2` : null
}

const loading = new Map()
/** Muat satu font (FontFace API); font dasar & lingkungan tanpa DOM dilewati. */
export function loadFont(family, weight = 400) {
  const f = BY[family]
  if (!f || f.base || typeof document === 'undefined' || typeof FontFace === 'undefined' || !document.fonts) return Promise.resolve()
  const w = nearestWeight(family, weight)
  const key = `${family}-${w}`
  if (!loading.has(key)) {
    const face = new FontFace(family, `url(${fontUrl(family, w)}) format("woff2")`, { weight: String(w), display: 'swap' })
    loading.set(key, face.load().then((ff) => { document.fonts.add(ff) }).catch(() => {}))
  }
  return loading.get(key)
}

/** Muat semua font yang dipakai layer teks. */
export const loadFontsFor = (layers = []) => Promise.all(layers.filter((l) => l.type === 'text').map((l) => loadFont(l.font, l.weight)))
