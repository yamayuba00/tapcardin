// Editor markdown email blast — fungsi murni untuk tombol toolbar (tanpa DOM) & ringkasan progres kirim.
// Setiap fungsi menerima teks + seleksi [start, end) dan mengembalikan { text, start, end } yang baru.

/** Bungkus seleksi dengan penanda (mis. ** tebal, _ miring); tanpa seleksi → sisipkan placeholder terseleksi. */
export function wrap(text, start, end, marker, placeholder = 'teks') {
  const sel = text.slice(start, end) || placeholder
  const out = text.slice(0, start) + marker + sel + marker + text.slice(end)
  return { text: out, start: start + marker.length, end: start + marker.length + sel.length }
}

/** Awal & akhir baris-baris yang tersentuh seleksi. */
function lineRange(text, start, end) {
  const from = text.lastIndexOf('\n', start - 1) + 1
  const nl = text.indexOf('\n', end)
  return [from, nl === -1 ? text.length : nl]
}

/** Beri awalan tiap baris terseleksi (judul `## `, daftar `- `); awalan yang sama dilepas lagi (toggle). */
export function prefixLines(text, start, end, prefix) {
  const [from, to] = lineRange(text, start, end)
  const lines = text.slice(from, to).split('\n')
  const all = lines.every((l) => l.startsWith(prefix))
  const bare = (l) => l.replace(/^(#{1,6} |- |\* |\d+\. )/, '')
  // baris kosong dibiarkan kosong; awalan lain (judul ↔ daftar) diganti
  const next = lines.map((l) => (all ? l.slice(prefix.length) : bare(l) ? prefix + bare(l) : l)).join('\n')
  const out = text.slice(0, from) + next + text.slice(to)
  return { text: out, start: from, end: from + next.length }
}

/** Sisipkan blok di baris sendiri (gambar, tombol, garis) dengan baris kosong di sekitarnya. */
export function insertBlock(text, start, end, block) {
  const before = text.slice(0, start).replace(/\s+$/, '')
  const after = text.slice(end).replace(/^\s+/, '')
  const head = before ? before + '\n\n' : ''
  const out = head + block + (after ? '\n\n' + after : '\n')
  const pos = head.length + block.length
  return { text: out, start: pos, end: pos }
}

/** Tautan `[teks](url)`; teks = seleksi atau url. */
export function link(text, start, end, url) {
  const label = text.slice(start, end) || 'tautan'
  const md = `[${label.replace(/[[\]]/g, '')}](${url.trim()})`
  const out = text.slice(0, start) + md + text.slice(end)
  return { text: out, start: start + md.length, end: start + md.length }
}

export const image = (text, start, end, url, alt = 'Gambar') => insertBlock(text, start, end, `![${alt.replace(/[[\]]/g, '')}](${url.trim()})`)

export const button = (text, start, end, label, url) => insertBlock(text, start, end, `[[Tombol: ${label.replace(/[|\]]/g, '').trim()} | ${url.trim()}]]`)

/** URL https absolut (sama dengan aturan server untuk tautan & tombol). */
export const isHttps = (url) => /^https:\/\/[^\s/?#@]+\.[^\s/?#@]+([/?#]\S*)?$/i.test(String(url ?? '').trim())

/** Progres kirim 0–100 dari hitungan blast (terkirim + gagal + dilewati dibanding total). */
export function progress(counts) {
  const total = counts?.total ?? 0
  if (!total) return 0
  const done = (counts.sent ?? 0) + (counts.failed ?? 0) + (counts.skipped ?? 0)
  return Math.min(100, Math.round((done / total) * 100))
}

export const STATUS = {
  draft: { label: 'Draf', tone: 'muted' },
  scheduled: { label: 'Terjadwal', tone: 'info' },
  sending: { label: 'Mengirim', tone: 'warn' },
  sent: { label: 'Terkirim', tone: 'ok' },
  cancelled: { label: 'Dibatalkan', tone: 'danger' },
}

export const AUDIENCE = { all: 'Semua pengguna', personal: 'Workspace personal', company: 'Workspace perusahaan', plan: 'Paket tertentu', test: 'Uji internal (staf panel)' }
