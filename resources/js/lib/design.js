// Logika murni Design Studio & editor lanyard (BSD §5): satuan, variabel, layer baru, posisi, kotak pembatas,
// rotasi, perataan/distribusi, grup, brand kit, salin elemen antar-desain. Tanpa DOM agar bisa diuji `node --test`.

export const PT_MM = 0.3528
export const BLEED = 3
export const SAFE = 4
export const MIN_TEXT_PT = 6

export const ptToMm = (pt) => pt * PT_MM
export const isSystem = (layer) => !!layer?.locked || String(layer?.id ?? '').startsWith('sys-')
/** Tidak bisa digeser/diubah dari kanvas: elemen sistem atau dikunci pengguna. */
export const isFixed = (layer) => isSystem(layer) || !!layer?.lock
export const round = (n, d = 2) => Math.round(n * 10 ** d) / 10 ** d

/** Isi {{variabel}} dengan nilai profil / brand kit; variabel tak dikenal dibiarkan apa adanya. */
export function fillVars(text, vars = {}) {
  return String(text ?? '').replace(/\{\{\s*(\w+)\s*\}\}/g, (m, k) => (vars[k] != null && vars[k] !== '' ? vars[k] : m))
}

/** Variabel dari brand kit — sama dengan App\Support\Design\Variables::forKit. */
export function kitVars(kit) {
  if (!kit) return {}
  return Object.fromEntries(Object.entries({ brand: kit.display_name || kit.name, tagline: kit.tagline, brand_website: kit.website }).filter(([, v]) => v))
}

/** Warna akhir: hex apa adanya, `kit:primary` dll. dari brand kit (cadangan bila kit belum dipilih). */
export function resolveColor(v, kit, fallback = 'currentColor') {
  if (typeof v !== 'string') return fallback
  if (v === 'none') return 'none'
  if (v.startsWith('kit:')) return kit?.colors?.[v.slice(4)] ?? fallback
  return v
}

let seq = 0
export function newId(existing = []) {
  const ids = new Set(existing.map((l) => l.id))
  let id
  do { id = `l${Date.now().toString(36).slice(-4)}${++seq}` } while (ids.has(id))
  return id
}

/** Layer baru di tengah kanvas. */
export function createLayer(type, { w, h }, color, extra = {}) {
  const cx = round(w / 2)
  const cy = round(h / 2)
  const s = Math.min(w, h) // lanyard: sisi pendek = lebar tali
  const base = {
    text: { type, text: 'Teks baru', x: cx, y: cy, size: Math.min(10, round(s / ptToMm(1) * 0.45, 0)), font: 'IBM Plex Sans', weight: 500, color, align: 'center' },
    rect: { type, x: round(cx - 10), y: round(cy - Math.min(5, s / 3)), w: 20, h: round(Math.min(10, (s * 2) / 3)), fill: color, rx: 0 },
    ellipse: { type, x: round(cx - Math.min(6, s / 3)), y: round(cy - Math.min(6, s / 3)), w: round(Math.min(12, (s * 2) / 3)), h: round(Math.min(12, (s * 2) / 3)), fill: color },
    polygon: { type, x: round(cx - Math.min(6, s / 3)), y: round(cy - Math.min(6, s / 3)), w: round(Math.min(12, (s * 2) / 3)), h: round(Math.min(12, (s * 2) / 3)), sides: 6, star: false, inner: 0.5, fill: color },
    star: { type: 'polygon', x: round(cx - Math.min(6, s / 3)), y: round(cy - Math.min(6, s / 3)), w: round(Math.min(12, (s * 2) / 3)), h: round(Math.min(12, (s * 2) / 3)), sides: 5, star: true, inner: 0.45, fill: color },
    line: { type, x1: round(cx - 10), y1: cy, x2: round(cx + 10), y2: cy, color, width: 0.3 },
    circle: { type, cx, cy, r: round(Math.min(6, s / 3)), stroke: color, strokeWidth: 0.3 },
    rings: { type, cx, cy, from: 6, to: 24, step: 4, color, opacity: 0.3 },
    glyph: { type, name: 'star', x: round(cx - Math.min(4, s / 3)), y: round(cy - Math.min(4, s / 3)), size: round(Math.min(8, (s * 2) / 3)), color, strokeWidth: 2 },
    image: { type, x: round(cx - 10), y: round(cy - Math.min(10, s / 3)), w: 20, h: round(Math.min(20, (s * 2) / 3)), fit: 'contain' },
  }[type]
  return { ...base, ...extra }
}

/** Geser layer sejauh (dx, dy) mm — menyesuaikan bentuk koordinat tiap tipe. */
export function moveLayer(layer, dx, dy) {
  const l = { ...layer }
  if ('x1' in l) {
    Object.assign(l, { x1: round(l.x1 + dx), y1: round(l.y1 + dy), x2: round(l.x2 + dx), y2: round(l.y2 + dy) })
  } else if ('cx' in l) {
    Object.assign(l, { cx: round(l.cx + dx), cy: round(l.cy + dy) })
  } else {
    Object.assign(l, { x: round(l.x + dx), y: round(l.y + dy) })
  }
  return l
}

const textWidth = (l, text, hgt) => text.length * hgt * (l.font === 'IBM Plex Mono' ? 0.6 : 0.55) * (1 + (l.tracking ?? 0))

/** Ukuran teks efektif (pt) setelah auto-fit `fitWidth`, minimal 6 pt — sama dengan DesignValidator::textSize. */
export function textSize(l, vars = {}) {
  if (!l.fitWidth || l.size < MIN_TEXT_PT) return l.size
  const w = textWidth(l, fillVars(l.text, vars), ptToMm(l.size))
  return w > l.fitWidth ? Math.max(MIN_TEXT_PT, round((l.size * l.fitWidth) / w, 1)) : l.size
}

/** Baris-baris teks (teks multi-baris dipisah \n). */
export const textLines = (l, vars = {}) => {
  const t = fillVars(l.text, vars)
  return (l.uppercase ? t.toUpperCase() : t).split('\n')
}

/** Kotak tanpa rotasi. */
export function rawBox(l, vars = {}) {
  switch (l.type) {
    case 'text': {
      const hgt = ptToMm(textSize(l, vars))
      const lines = textLines(l, vars)
      const wid = Math.max(...lines.map((line) => textWidth(l, line, hgt)))
      const x = l.align === 'center' ? l.x - wid / 2 : l.align === 'right' ? l.x - wid : l.x
      return { x, y: l.y - hgt * 0.8, w: wid, h: hgt + hgt * (l.lineHeight ?? 1.25) * (lines.length - 1) }
    }
    case 'rect': case 'image': case 'ellipse': case 'polygon': return { x: l.x, y: l.y, w: l.w, h: l.h }
    case 'line': return { x: Math.min(l.x1, l.x2), y: Math.min(l.y1, l.y2) - l.width / 2, w: Math.abs(l.x2 - l.x1) || l.width, h: Math.abs(l.y2 - l.y1) || l.width }
    case 'circle': return { x: l.cx - l.r, y: l.cy - l.r, w: l.r * 2, h: l.r * 2 }
    case 'rings': return { x: l.cx - l.to, y: l.cy - l.to, w: l.to * 2, h: l.to * 2 }
    case 'icon': case 'glyph': case 'qr': return { x: l.x, y: l.y, w: l.size, h: l.size }
    case 'shortcode': case 'brand': {
      const hgt = ptToMm(l.size) * 1.4
      const wid = l.type === 'brand' ? hgt * 4.2 : hgt * 7
      return { x: l.align === 'center' ? l.x - wid / 2 : l.x, y: l.y - hgt * 0.8, w: wid, h: hgt }
    }
    default: return null
  }
}

/** Pusat rotasi layer (tengah kotak tanpa rotasi). */
export function center(l, vars = {}) {
  const b = rawBox(l, vars)
  return b ? { x: b.x + b.w / 2, y: b.y + b.h / 2 } : { x: 0, y: 0 }
}

/** Kotak pembatas setelah rotasi — sama dengan App\Support\Design\DesignValidator::box. */
export function layerBox(l, vars = {}) {
  const b = rawBox(l, vars)
  if (!b || !l.rotate) return b
  const r = (l.rotate * Math.PI) / 180
  const w = Math.abs(b.w * Math.cos(r)) + Math.abs(b.h * Math.sin(r))
  const h = Math.abs(b.w * Math.sin(r)) + Math.abs(b.h * Math.cos(r))
  return { x: b.x + b.w / 2 - w / 2, y: b.y + b.h / 2 - h / 2, w, h }
}

/** Gabungan kotak beberapa layer. */
export function unionBox(layers, vars = {}) {
  const boxes = layers.map((l) => layerBox(l, vars)).filter(Boolean)
  if (!boxes.length) return null
  const x = Math.min(...boxes.map((b) => b.x))
  const y = Math.min(...boxes.map((b) => b.y))
  return { x, y, w: Math.max(...boxes.map((b) => b.x + b.w)) - x, h: Math.max(...boxes.map((b) => b.y + b.h)) - y }
}

/** Titik poligon/bintang di dalam kotak (x, y, w, h). */
export function polygonPoints(l) {
  const n = Math.max(3, Math.min(12, Math.round(l.sides ?? 6)))
  const cx = l.x + l.w / 2
  const cy = l.y + l.h / 2
  const pts = []
  const count = l.star ? n * 2 : n
  for (let i = 0; i < count; i++) {
    const a = -Math.PI / 2 + (i * 2 * Math.PI) / count
    const k = l.star && i % 2 ? (l.inner ?? 0.5) : 1
    pts.push(`${round(cx + (Math.cos(a) * l.w * k) / 2, 3)},${round(cy + (Math.sin(a) * l.h * k) / 2, 3)}`)
  }
  return pts.join(' ')
}

/**
 * Ubah ukuran dari keadaan awal `l` dengan menarik `handle` sejauh total (dx, dy) mm.
 * Kotak/gambar/elips/poligon: sudut nw|ne|sw|se (gambar & Shift = rasio tetap). Teks & ikon: skala ukuran.
 * Lingkaran/cincin: jari-jari. Garis: ujung p1|p2.
 */
export function resizeLayer(l, handle, dx, dy, { keepAspect = false, vars = {} } = {}) {
  const out = { ...l }
  if (l.type === 'line') {
    const k = handle === 'p1' ? ['x1', 'y1'] : ['x2', 'y2']
    out[k[0]] = round(l[k[0]] + dx)
    out[k[1]] = round(l[k[1]] + dy)
    return out
  }
  if (l.type === 'circle' || l.type === 'rings') {
    const key = l.type === 'circle' ? 'r' : 'to'
    const sx = handle.includes('e') ? 1 : -1
    const sy = handle.includes('s') ? 1 : -1
    const min = l.type === 'rings' ? l.from + l.step : 0.5
    out[key] = round(Math.max(min, l[key] + Math.max(dx * sx, dy * sy)))
    return out
  }
  if (l.type === 'glyph' || l.type === 'icon') {
    const sx = handle.includes('e') ? 1 : -1
    const sy = handle.includes('s') ? 1 : -1
    const size = round(Math.max(2, Math.min(60, l.size + Math.max(dx * sx, dy * sy))))
    return { ...out, size, x: handle.includes('w') ? round(l.x + l.size - size) : l.x, y: handle.includes('n') ? round(l.y + l.size - size) : l.y }
  }
  if (l.type === 'text') {
    const box = rawBox(l, vars)
    const sx = handle.includes('e') ? 1 : -1
    // teks rata tengah tumbuh ke dua arah → tarikan dx menambah lebar 2×dx
    const grow = dx * sx * (l.align === 'center' ? 2 : 1)
    const scale = Math.max(0.1, (box.w + grow) / Math.max(box.w, 0.1))
    out.size = round(Math.min(72, Math.max(4, l.size * scale)), 1)
    return out
  }
  // rect / image / ellipse / polygon
  let { x, y, w, h } = l
  const MIN = 1
  if (handle.includes('e')) w = l.w + dx
  if (handle.includes('w')) w = l.w - dx
  if (handle.includes('s')) h = l.h + dy
  if (handle.includes('n')) h = l.h - dy
  w = Math.max(MIN, w)
  h = Math.max(MIN, h)
  if (keepAspect || (l.type === 'image' && l.fit !== 'cover')) {
    const ratio = l.w / l.h
    // ikuti sumbu yang berubah paling besar
    if (Math.abs(w - l.w) / l.w >= Math.abs(h - l.h) / l.h) h = w / ratio
    else w = h * ratio
  }
  if (handle.includes('w')) x = l.x + l.w - w
  if (handle.includes('n')) y = l.y + l.h - h
  return { ...out, x: round(x), y: round(y), w: round(w), h: round(h) }
}

/** Sudut (°) dari pusat ke titik pointer; dibulatkan 15° bila `snap`. */
export function angleTo(c, p, snap = false) {
  let deg = (Math.atan2(p.y - c.y, p.x - c.x) * 180) / Math.PI + 90
  if (deg > 180) deg -= 360
  if (snap) deg = Math.round(deg / 15) * 15
  return round(deg, 1)
}

/**
 * Snap geser: kotak (sesudah dx, dy) menempel ke garis panduan dalam `threshold` mm.
 * Target: tepi kanvas, tengah, margin aman, grid (opsional), dan tepi/tengah layer lain.
 * @returns {{dx:number, dy:number, guides:{x:number[], y:number[]}}}
 */
export function snapMove(box, dx, dy, { size, others = [], threshold = 1, safe = SAFE, grid = 0 }) {
  const tx = [0, safe, size.w / 2, size.w - safe, size.w]
  const ty = [0, safe, size.h / 2, size.h - safe, size.h]
  for (const o of others) {
    tx.push(o.x, o.x + o.w / 2, o.x + o.w)
    ty.push(o.y, o.y + o.h / 2, o.y + o.h)
  }
  if (grid > 0) {
    for (let v = 0; v <= size.w; v += grid) tx.push(round(v))
    for (let v = 0; v <= size.h; v += grid) ty.push(round(v))
  }
  const axis = (start, len, d, targets) => {
    let best = null
    for (const p of [start + d, start + d + len / 2, start + d + len]) {
      for (const t of targets) {
        const diff = t - p
        if (Math.abs(diff) <= threshold && (!best || Math.abs(diff) < Math.abs(best.diff))) best = { diff, at: t }
      }
    }
    return best
  }
  const bx = axis(box.x, box.w, dx, tx)
  const by = axis(box.y, box.h, dy, ty)
  const ndx = bx ? dx + bx.diff : dx
  const ndy = by ? dy + by.diff : dy
  // tampilkan semua garis yang kini tepat menempel (mis. tengah & tepi sekaligus); garis grid tidak ditampilkan
  const grids = (v, max) => grid > 0 && Math.abs(v / grid - Math.round(v / grid)) < 0.001 && ![0, safe, max / 2, max - safe, max].includes(v)
  const hit = (start, len, d, targets, max) => [...new Set(targets.filter((t) => !grids(t, max) && [start + d, start + d + len / 2, start + d + len].some((p) => Math.abs(p - t) < 0.01)))]
  return {
    dx: round(ndx, 3), dy: round(ndy, 3),
    guides: { x: bx ? hit(box.x, box.w, ndx, tx, size.w) : [], y: by ? hit(box.y, box.h, ndy, ty, size.h) : [] },
  }
}

/**
 * Ratakan layer. Satu layer → terhadap kanvas; beberapa → terhadap gabungan kotaknya.
 * @param {'left'|'hcenter'|'right'|'top'|'vmiddle'|'bottom'} how
 */
export function alignLayers(layers, how, size, vars = {}) {
  const ref = layers.length > 1 ? unionBox(layers, vars) : { x: 0, y: 0, w: size.w, h: size.h }
  return layers.map((l) => {
    const b = layerBox(l, vars)
    if (!b) return l
    const dx = { left: ref.x - b.x, hcenter: ref.x + ref.w / 2 - (b.x + b.w / 2), right: ref.x + ref.w - (b.x + b.w) }[how] ?? 0
    const dy = { top: ref.y - b.y, vmiddle: ref.y + ref.h / 2 - (b.y + b.h / 2), bottom: ref.y + ref.h - (b.y + b.h) }[how] ?? 0
    return moveLayer(l, dx, dy)
  })
}

/** Jarak antar-layer sama rata (≥ 3 layer), urut menurut posisi. @param {'h'|'v'} axis */
export function distributeLayers(layers, axis, vars = {}) {
  if (layers.length < 3) return layers
  const key = axis === 'h' ? ['x', 'w'] : ['y', 'h']
  const items = layers.map((l, i) => ({ l, i, b: layerBox(l, vars) })).filter((o) => o.b).sort((a, z) => a.b[key[0]] - z.b[key[0]])
  const first = items[0].b
  const last = items[items.length - 1].b
  const total = items.reduce((s, o) => s + o.b[key[1]], 0)
  const gap = (last[key[0]] + last[key[1]] - first[key[0]] - total) / (items.length - 1)
  const out = [...layers]
  let pos = first[key[0]]
  for (const o of items) {
    const d = pos - o.b[key[0]]
    out[o.i] = axis === 'h' ? moveLayer(o.l, d, 0) : moveLayer(o.l, 0, d)
    pos += o.b[key[1]] + gap
  }
  return out
}

/** Perluas seleksi ke semua anggota grup yang sama. */
export function expandGroups(ids, layers) {
  const groups = new Set(layers.filter((l) => ids.includes(l.id) && l.group).map((l) => l.group))
  return layers.filter((l) => ids.includes(l.id) || (l.group && groups.has(l.group))).map((l) => l.id)
}

/**
 * Salin layer ke kanvas lain (kartu ↔ lanyard): diskalakan agar muat di area aman target & ditaruh di tengah.
 * Id baru dibuat; grup dipertahankan dalam salinan.
 */
export function transplantLayers(layers, target, { safe = SAFE, vars = {}, existing = [] } = {}) {
  if (!unionBox(layers, vars)) return []
  const availW = target.w - safe * 2
  const availH = target.h - safe * 2
  // teks dijepit minimal 6 pt sehingga skala pertama bisa sedikit meleset → ulangi sampai muat
  let cur = layers
  for (let i = 0; i < 4; i++) {
    const box = unionBox(cur, vars)
    const k = Math.min(1, availW / box.w, availH / box.h)
    cur = cur.map((l) => scaleLayer(l, k, box))
    if (k > 0.995) break
  }
  const box = unionBox(cur, vars)
  const ox = (target.w - box.w) / 2 - box.x
  const oy = (target.h - box.h) / 2 - box.y
  const groupMap = {}
  const taken = [...existing]
  return cur.map((l) => {
    const moved = moveLayer(l, ox, oy)
    const id = newId(taken)
    taken.push({ id })
    const group = l.group ? (groupMap[l.group] ??= `g${id}`) : undefined
    return { ...moved, id, ...(group ? { group } : {}) }
  })
}

/** Skala layer relatif sudut kiri-atas `origin` (koordinat jadi relatif origin). */
function scaleLayer(l, k, origin) {
  const X = (v) => round((v - origin.x) * k)
  const Y = (v) => round((v - origin.y) * k)
  const S = (v) => round(v * k)
  switch (l.type) {
    case 'line': return { ...l, x1: X(l.x1), y1: Y(l.y1), x2: X(l.x2), y2: Y(l.y2), width: Math.max(0.1, S(l.width)) }
    case 'circle': return { ...l, cx: X(l.cx), cy: Y(l.cy), r: Math.max(0.5, S(l.r)) }
    case 'rings': return { ...l, cx: X(l.cx), cy: Y(l.cy), from: S(l.from), to: S(l.to), step: Math.max(1, S(l.step)) }
    case 'text': return { ...l, x: X(l.x), y: Y(l.y), size: Math.max(MIN_TEXT_PT, round(l.size * k, 1)), ...(l.fitWidth ? { fitWidth: S(l.fitWidth) } : {}) }
    case 'glyph': case 'icon': return { ...l, x: X(l.x), y: Y(l.y), size: Math.max(2, S(l.size)) }
    default: return { ...l, x: X(l.x), y: Y(l.y), w: Math.max(1, S(l.w)), h: Math.max(1, S(l.h)) }
  }
}

/** Ukuran kartu tanpa bleed. */
export const cardSize = (orientation) => (orientation === 'portrait' ? { w: 54, h: 85.6 } : { w: 85.6, h: 54 })

export const LAYER_LABELS = {
  text: 'Teks', rect: 'Kotak', line: 'Garis', circle: 'Lingkaran', rings: 'Cincin sinyal', image: 'Gambar', ellipse: 'Elips',
  polygon: 'Poligon', glyph: 'Ikon', icon: 'Ikon NFC', qr: 'QR code', shortcode: 'Kode pendek', brand: 'TapCard',
}

export function layerLabel(l, vars) {
  if (l.name) return l.name
  if (l.id === 'sys-tapzone') return 'Zona TAP'
  if (l.id === 'sys-tapicon') return 'Ikon zona TAP'
  if (l.type === 'text') return fillVars(l.text, vars).replace(/\n/g, ' ').slice(0, 28) || 'Teks'
  if (l.type === 'polygon' && l.star) return 'Bintang'
  if (l.type === 'image' && l.asset === 'kit:logo') return 'Logo merek'
  if (l.type === 'glyph') return `Ikon ${l.name}`
  return LAYER_LABELS[l.type] ?? l.type
}
