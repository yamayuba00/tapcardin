// Blok bio link: katalog jenis, nilai awal, dan bentuk publik untuk preview langsung di editor.
// Pengurai video harus sama dengan App\Support\Bio\BioBlocks::video() (server tetap memvalidasi saat simpan).

export const MAX_PAGES = 3
export const MAX_BLOCKS = 30

export const SAVE_LABEL = 'Simpan ke Kontak HP'
/** Blok yang cukup satu per halaman (sama dengan BioBlocks::SINGLE). */
export const SINGLE = ['save_contact', 'contacts']

export const BLOCK_TYPES = [
  { type: 'save_contact', label: 'Simpan kontak', hint: 'Pengunjung menyimpan nomor Anda ke HP-nya (cukup satu)' },
  { type: 'button', label: 'Tombol', hint: 'Ajak kolaborasi, minta penawaran, booking' },
  { type: 'text', label: 'Teks', hint: 'Judul bagian, tentang saya, layanan' },
  { type: 'video', label: 'Video / musik', hint: 'YouTube, TikTok, Vimeo, Spotify' },
  { type: 'divider', label: 'Pemisah', hint: 'Garis atau jarak antar bagian' },
  { type: 'contacts', label: 'Kontak & tautan', hint: 'Daftar kontak dari bagian Kontak (cukup satu)' },
]

export function newBlock(type) {
  const data = {
    button: { label: '', url: '', style: 'primary' },
    text: { title: '', body: '', align: 'left' },
    video: { url: '' },
    divider: { style: 'line' },
    contacts: {},
    save_contact: { label: SAVE_LABEL },
  }[type]
  return { id: null, key: crypto.randomUUID(), type, is_visible: true, data: structuredClone(data) }
}

const YT_ID = /^[A-Za-z0-9_-]{11}$/

/** @returns {{provider:string,id:string,kind:string}|null} */
export function parseVideo(input) {
  let u
  try { u = new URL(String(input ?? '').trim()) } catch { return null }
  if (!['http:', 'https:'].includes(u.protocol)) return null
  const host = u.hostname.toLowerCase().replace(/^(www|m|music)\./, '')
  const path = u.pathname
  const yt = (id, kind = 'video') => (YT_ID.test(id ?? '') ? { provider: 'youtube', id, kind } : null)
  let m
  if (host === 'youtu.be') return yt(path.replace(/^\/+/, '').split('/')[0])
  if (host === 'youtube.com' || host === 'youtube-nocookie.com') {
    if (path === '/watch') return yt(u.searchParams.get('v'))
    if ((m = path.match(/^\/shorts\/([^/]+)/))) return yt(m[1], 'short')
    if ((m = path.match(/^\/(embed|live)\/([^/]+)/))) return yt(m[2])
    return null
  }
  if (host === 'vimeo.com') return (m = path.match(/^\/(?:.*\/)?(\d{6,12})\/?$/)) ? { provider: 'vimeo', id: m[1], kind: 'video' } : null
  if (host === 'player.vimeo.com') return (m = path.match(/^\/video\/(\d{6,12})\/?$/)) ? { provider: 'vimeo', id: m[1], kind: 'video' } : null
  if (host === 'tiktok.com') return (m = path.match(/^\/@[^/]+\/video\/(\d{10,25})\/?$/)) ? { provider: 'tiktok', id: m[1], kind: 'video' } : null
  if (host === 'open.spotify.com') {
    m = path.match(/^\/(?:intl-[a-z-]+\/)?(track|album|playlist|episode|show|artist)\/([A-Za-z0-9]{22})\/?$/)
    return m ? { provider: 'spotify', id: m[2], kind: m[1] } : null
  }
  return null
}

export function embedOf({ provider, id, kind }) {
  switch (provider) {
    case 'youtube': return { embed_url: `https://www.youtube-nocookie.com/embed/${id}`, ratio: kind === 'short' ? '9/16' : '16/9', height: null }
    case 'vimeo': return { embed_url: `https://player.vimeo.com/video/${id}`, ratio: '16/9', height: null }
    case 'tiktok': return { embed_url: `https://www.tiktok.com/embed/v2/${id}`, ratio: null, height: 740 }
    case 'spotify': return { embed_url: `https://open.spotify.com/embed/${kind}/${id}`, ratio: null, height: ['track', 'episode'].includes(kind) ? 152 : 352 }
    default: return null
  }
}

/** Blok editor → bentuk publik (sama dengan BioBlocks::present). Blok yang belum lengkap tidak ditampilkan. */
export function presentBlock(b) {
  const d = b.data ?? {}
  const base = { id: b.id ?? b.key, type: b.type }
  switch (b.type) {
    case 'button': return d.label?.trim() ? { ...base, label: d.label.trim(), url: d.url, style: d.style } : null
    case 'text': return d.title?.trim() || d.body?.trim() ? { ...base, title: d.title?.trim() ?? '', body: d.body?.trim() ?? '', align: d.align } : null
    case 'video': {
      const v = parseVideo(d.url)
      return v ? { ...base, provider: v.provider, ...embedOf(v) } : null
    }
    case 'divider': return { ...base, style: d.style }
    case 'contacts': return base
    case 'save_contact': return { ...base, label: d.label?.trim() || SAVE_LABEL }
    default: return null
  }
}

/** Blok yang dikirim ke PUT /api/profiles/{id}/pages/{page} (tanpa kunci lokal). */
export function blockPayload(b) {
  return { id: b.id || undefined, type: b.type, is_visible: b.is_visible, data: b.data }
}
