// Format angka, tanggal relatif, dan label — murni, diuji dengan `node --test`.

const tz = 'Asia/Jakarta'

/** @param {number|null|undefined} n */
export const num = (n) => (n == null ? '—' : new Intl.NumberFormat('id-ID').format(n))

/** @param {number|null|undefined} n */
export const rupiah = (n) => (n == null ? '—' : 'Rp ' + new Intl.NumberFormat('id-ID').format(n))

/** @param {string|null} iso */
export function dateTime(iso) {
  if (!iso) return '—'
  return new Intl.DateTimeFormat('id-ID', { timeZone: tz, day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso))
}

/** @param {string|null} iso */
export function date(iso) {
  if (!iso) return '—'
  return new Intl.DateTimeFormat('id-ID', { timeZone: tz, day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(iso))
}

/**
 * "baru saja", "5 menit lalu", "3 jam lalu", "kemarin", "4 hari lalu", lalu tanggal.
 * @param {string|null} iso @param {Date} [now]
 */
export function relative(iso, now = new Date()) {
  if (!iso) return '—'
  const s = Math.round((now.getTime() - new Date(iso).getTime()) / 1000)
  if (s < 60) return 'baru saja'
  const m = Math.floor(s / 60)
  if (m < 60) return `${m} menit lalu`
  const h = Math.floor(m / 60)
  if (h < 24) return `${h} jam lalu`
  const d = Math.floor(h / 24)
  if (d === 1) return 'kemarin'
  if (d < 7) return `${d} hari lalu`
  return date(iso)
}

/** Persen perubahan; null jika pembanding 0. */
export function delta(current, previous) {
  if (!previous) return null
  return Math.round(((current - previous) / previous) * 100)
}

export const SOURCE_LABELS = {
  nfc: 'NFC', qr: 'QR', dynamic_qr: 'QR aman', code: 'Kode', link: 'Tautan', app: 'Aplikasi',
  exchange_form: 'Tukar Kontak', scan_tapcard: 'Scan TapCard', scan_external: 'Scan kartu lain',
  manual: 'Manual', import: 'Impor', event_checkin: 'Check-in', saved_from_profile: 'Dari profil', bio_form: 'Formulir bio',
}
export const sourceLabel = (s) => SOURCE_LABELS[s] ?? s

export const ACTIVITY_LABELS = {
  note: 'Catatan', call: 'Telepon', whatsapp: 'WhatsApp', email: 'Email', meeting: 'Meeting',
  stage_changed: 'Tahap diubah', assigned: 'Dialihkan', tap: 'Tap ulang', merged: 'Digabung',
  created: 'Dibuat', system: 'Sistem',
}

/** AFD §4.1 + DBD §3.5 — label & tone status kartu (sama dengan App\Enums\CardStatus). */
export const CARD_STATUS = {
  manufactured: { label: 'Diproduksi', tone: 'muted' },
  unclaimed: { label: 'Belum diaktifkan', tone: 'info' },
  active: { label: 'Aktif', tone: 'ok' },
  paused: { label: 'Dijeda', tone: 'warn' },
  lost: { label: 'Hilang', tone: 'danger' },
  revoked: { label: 'Tidak berlaku', tone: 'muted' },
}

export function initials(name) {
  return (name || '').trim().split(/\s+/).slice(0, 2).map((w) => w[0]?.toUpperCase() ?? '').join('')
}

/** Normalisasi nomor ke E.164 (+62) — sama dengan App\Support\Phone. */
export function toE164(raw, cc = '62') {
  if (!raw || !raw.trim()) return null
  const plus = raw.trim().startsWith('+')
  let d = raw.replace(/\D+/g, '')
  if (!d) return null
  if (!plus) {
    if (d.startsWith('00')) d = d.slice(2)
    else if (d.startsWith('0')) d = cc + d.slice(1)
    else if (!d.startsWith(cc)) d = cc + d
  }
  return d.length >= 8 && d.length <= 15 ? '+' + d : null
}
