// Layout siap pakai untuk mode sederhana editor merchandise. Setiap layout menyusun lapisan bernama tetap
// (s-logo, s-title, s-sub, s-line) di area cetak produk (mm) sehingga panel sederhana cukup mengubah isinya.
// Teks memakai `fitWidth` (diperkecil otomatis bila terlalu panjang) dan selalu berada di dalam margin aman.

export const SAFE = 5
const PT_MM = 0.3528
const round = (n) => Math.round(n * 100) / 100
/** Ukuran teks (pt) dari tinggi huruf yang diinginkan (mm), dibatasi 6–120 pt seperti normalizer server. */
const pt = (mm) => Math.max(6, Math.min(120, Math.round(mm / PT_MM / 0.72)))
/** baseline teks agar huruf kapital tampak di tengah `cy` */
const baseline = (cy, size) => round(cy + size * 0.127)

function text(id, value, cx, cy, heightMm, maxW, c, extra = {}) {
  const size = pt(heightMm)
  return { id, type: 'text', text: value, x: round(cx), y: baseline(cy, size), size, font: 'Syne', weight: 700, color: c.ink, align: 'center', fitWidth: round(maxW), ...extra }
}
function logo(id, cx, cy, w, h, c) {
  const l = { id, type: 'image', fit: 'contain', x: round(cx - w / 2), y: round(cy - h / 2), w: round(w), h: round(h) }
  return c.logo ? { ...l, ...c.logo } : { ...l, asset: 'kit:logo' }
}

/** Bangun lapisan layout. `c` = { title, sub, ink, logo? {asset,url,px_w,px_h} } */
const BUILDERS = {
  // kaos & jaket — depan
  chest: ({ w }, c) => {
    const box = Math.min(80, w * 0.3)
    const cx = w - SAFE - box / 2 - w * 0.06 // dada kiri pemakai = sisi kanan gambar
    return [logo('s-logo', cx, SAFE + 20 + box * 0.35, box, box * 0.7, c), text('s-title', c.title, cx, SAFE + 30 + box * 0.75, Math.min(9, box / 8), box, c)]
  },
  center: ({ w, h }, c) => {
    const box = Math.min(w, h) * 0.42
    const gap = Math.max(4, w * 0.03)
    const tH = Math.min(20, w / 12) // tinggi huruf judul (mm)
    const sH = Math.min(8, w / 28)
    const tCy = h * 0.3 + box / 2 + gap + tH / 2
    return [
      logo('s-logo', w / 2, h * 0.3, box, box, c),
      text('s-title', c.title, w / 2, tCy, tH, w - 2 * SAFE, c),
      text('s-sub', c.sub, w / 2, tCy + tH / 2 + gap + sH / 2, sH, w - 2 * SAFE, c, { font: 'IBM Plex Sans', weight: 500, uppercase: true, tracking: 0.18 }),
    ]
  },
  bigtext: ({ w, h }, c) => [
    text('s-title', c.title, w / 2, h * 0.45, Math.min(h * 0.16, w / 7), w - 2 * SAFE, c),
    { id: 's-line', type: 'rect', x: round(w * 0.3), y: round(h * 0.45 + Math.min(h * 0.16, w / 7) * 0.9), w: round(w * 0.4), h: round(Math.max(1, w / 120)), fill: c.ink, rx: 0 },
    text('s-sub', c.sub, w / 2, h * 0.45 + Math.min(h * 0.16, w / 7) * 1.6, Math.min(10, w / 24), w - 2 * SAFE, c, { font: 'IBM Plex Sans', weight: 500, uppercase: true, tracking: 0.2 }),
  ],
  // kaos & jaket — belakang
  back: ({ w, h }, c) => [
    text('s-title', c.title, w / 2, h * 0.22, Math.min(h * 0.11, w / 9), w - 2 * SAFE, c),
    text('s-sub', c.sub, w / 2, h * 0.22 + Math.min(h * 0.11, w / 9) * 1.5, Math.min(10, w / 24), w - 2 * SAFE, c, { font: 'IBM Plex Sans', weight: 500, uppercase: true, tracking: 0.2 }),
    logo('s-logo', w / 2, h * 0.62, Math.min(w, h) * 0.4, Math.min(w, h) * 0.4, c),
  ],
  // nama di atas, nomor besar (teks kedua = nomor; bukan angka → 10)
  jersey: ({ w, h }, c) => [
    text('s-title', c.title, w / 2, h * 0.15, Math.min(h * 0.05, w / 16), w - 2 * SAFE, c, { uppercase: true }),
    text('s-sub', /^\d{1,3}$/.test(String(c.sub).trim()) ? String(c.sub).trim() : '10', w / 2, h * 0.5, Math.min(h * 0.38, w * 0.5), w - 2 * SAFE, c),
  ],
  // tote bag
  stacked: ({ w, h }, c) => [
    logo('s-logo', w / 2, h * 0.36, w * 0.42, h * 0.36, c),
    text('s-title', c.title, w / 2, h * 0.68, Math.min(18, w / 12), w - 2 * SAFE, c),
    text('s-sub', c.sub, w / 2, h * 0.68 + Math.min(18, w / 12) * 1.4, Math.min(8, w / 30), w - 2 * SAFE, c, { font: 'IBM Plex Sans', weight: 500, uppercase: true, tracking: 0.18 }),
  ],
  // mug (area keliling)
  mugcenter: ({ w, h }, c) => [
    logo('s-logo', w / 2, h * 0.4, h * 0.55, h * 0.55, c),
    text('s-title', c.title, w / 2, h * 0.82, h * 0.12, w * 0.6, c),
  ],
  mugsides: ({ w, h }, c) => [
    logo('s-logo', w * 0.25, h / 2, h * 0.6, h * 0.6, c),
    text('s-title', c.title, w * 0.72, h * 0.42, h * 0.14, w * 0.42, c),
    text('s-sub', c.sub, w * 0.72, h * 0.62, h * 0.07, w * 0.42, c, { font: 'IBM Plex Sans', weight: 500, uppercase: true, tracking: 0.15 }),
  ],
  mugwrap: ({ w, h }, c) => [0.17, 0.5, 0.83].map((f, i) => text(i ? `s-title-${i}` : 's-title', c.title, w * f, h / 2, h * 0.16, w * 0.3, c)),
  // topi
  cap: ({ w, h }, c) => [logo('s-logo', w / 2, h / 2, h * 0.8, h * 0.8, c)],
  captext: ({ w, h }, c) => [text('s-title', c.title, w / 2, h / 2, h * 0.3, w - 2 * SAFE, c, { uppercase: true, tracking: 0.06 })],
}

const L = (key, label, hint) => ({ key, label, hint })
/** Layout per siluet produk & sisi. */
export const LAYOUTS = {
  tshirt: {
    front: [L('chest', 'Logo dada', 'Kecil di dada kiri pemakai'), L('center', 'Logo tengah', 'Logo + nama + tagline'), L('bigtext', 'Tulisan besar', 'Nama merek besar di dada')],
    back: [L('back', 'Punggung', 'Nama besar + logo'), L('jersey', 'Nama & nomor', 'Gaya jersey tim'), L('bigtext', 'Tulisan besar', 'Satu kalimat besar')],
  },
  jacket: {
    front: [L('chest', 'Logo dada', 'Kecil di dada kiri pemakai'), L('bigtext', 'Tulisan depan', 'Nama merek di dada')],
    back: [L('back', 'Punggung', 'Nama besar + logo'), L('jersey', 'Nama & nomor', 'Untuk tim / komunitas'), L('center', 'Logo besar', 'Logo di tengah punggung')],
  },
  totebag: {
    front: [L('stacked', 'Logo + nama', 'Logo besar di atas nama'), L('bigtext', 'Tulisan besar', 'Satu kalimat besar'), L('center', 'Logo tengah', 'Logo + nama + tagline')],
    back: [L('bigtext', 'Tulisan besar', 'Satu kalimat besar'), L('stacked', 'Logo + nama', 'Sama dengan depan')],
  },
  mug: { front: [L('mugcenter', 'Logo tengah', 'Logo menghadap pemegang'), L('mugsides', 'Logo + teks', 'Logo kiri, nama kanan'), L('mugwrap', 'Nama keliling', 'Nama diulang mengelilingi mug')] },
  cap: { front: [L('cap', 'Logo', 'Logo di tengah depan'), L('captext', 'Tulisan', 'Nama / slogan pendek')] },
}

export function layoutsFor(silhouette, which) {
  const s = LAYOUTS[silhouette] ?? LAYOUTS.tshirt
  return s[which] ?? s.front
}

/** Lapisan untuk layout `key` pada area {w,h}; `c` = { title, sub, ink, logo? }. */
export function buildLayout(key, size, c) {
  const fn = BUILDERS[key]
  if (!fn) throw new Error(`layout tidak dikenal: ${key}`)
  return fn(size, { title: c.title ?? '', sub: c.sub ?? '', ink: c.ink, logo: c.logo ?? null })
}

/** Lapisan yang dikelola mode sederhana (bisa diganti isinya dari panel). */
export const isSimple = (l) => typeof l?.id === 'string' && l.id.startsWith('s-')
export const titleLayers = (layers) => layers.filter((l) => l.type === 'text' && (l.id === 's-title' || l.id.startsWith('s-title-')))
