// Validasi murni untuk server WebSocket: origin, host, pesan, kode pasangan & pembatas percobaan.
import { randomInt, timingSafeEqual } from 'node:crypto'

export const DEFAULT_PORT = 47321
export const DEFAULT_ORIGINS = ['http://panel.localhost:8000', 'https://panel.localhost:8000']
export const BIND_HOST = '127.0.0.1'
export const MAX_URL_LENGTH = 300

/** Daftar origin yang diizinkan: bawaan dev + NFC_BRIDGE_ORIGINS (dipisah koma). Origin dinormalkan via URL. */
export function parseOrigins(env = '') {
  const extra = String(env).split(',').map((s) => s.trim()).filter(Boolean)
  const out = new Set()
  for (const o of [...DEFAULT_ORIGINS, ...extra]) {
    try {
      const u = new URL(o)
      if (u.protocol === 'http:' || u.protocol === 'https:') out.add(u.origin)
    } catch { /* abaikan entri rusak */ }
  }
  return out
}

export function isAllowedOrigin(origin, allowed) {
  if (!origin || origin === 'null') return false
  try {
    // header Origin persis "skema://host[:port]"; tolak bentuk lain (path, userinfo) meski URL-nya bisa dinormalkan
    return new URL(origin).origin === origin && allowed.has(origin)
  } catch {
    return false
  }
}

/** Tolak DNS rebinding: header Host wajib 127.0.0.1:<port> atau localhost:<port>. */
export function isAllowedHost(host, port) {
  return host === `${BIND_HOST}:${port}` || host === `localhost:${port}`
}

/** Port dari env, atau bawaan. */
export function parsePort(value) {
  if (value === undefined || value === '') return DEFAULT_PORT
  const n = Number(value)
  if (!Number.isInteger(n) || n < 1024 || n > 65535) throw new Error(`NFC_BRIDGE_PORT tidak valid: ${value}`)
  return n
}

export const generatePairingCode = () => String(randomInt(0, 1_000_000)).padStart(6, '0')

export function codesEqual(a, b) {
  const x = Buffer.from(String(a ?? ''))
  const y = Buffer.from(String(b ?? ''))
  return x.length === y.length && timingSafeEqual(x, y)
}

/** Pembatas percobaan pasangan: maks `max` gagal per `windowMs` (global, karena semua klien dari mesin yang sama). */
export function createAttemptLimiter({ max = 5, windowMs = 60_000, now = Date.now } = {}) {
  let fails = []
  return {
    blocked() {
      const t = now()
      fails = fails.filter((f) => t - f < windowMs)
      return fails.length >= max
    },
    fail() { fails.push(now()) },
    reset() { fails = [] },
  }
}

const TOKEN_RE = /^[0-9A-Za-z]{10}$/

/**
 * Validasi pesan klien. Mengembalikan { ok: true, msg } (dinormalkan) atau { ok: false, error }.
 * Tipe: auth {code}, status, write {url, token, lock?}. `id` opsional (string/angka) untuk korelasi balasan.
 */
export function validateMessage(raw) {
  let m
  try {
    m = typeof raw === 'string' ? JSON.parse(raw) : JSON.parse(Buffer.from(raw).toString('utf8'))
  } catch {
    return { ok: false, error: 'Pesan bukan JSON' }
  }
  if (!m || typeof m !== 'object' || Array.isArray(m)) return { ok: false, error: 'Pesan tidak valid' }
  const id = m.id
  if (id !== undefined && !((typeof id === 'string' && id.length <= 64) || Number.isInteger(id))) {
    return { ok: false, error: 'id tidak valid' }
  }
  switch (m.type) {
    case 'auth':
      if (typeof m.code !== 'string' || !/^\d{6}$/.test(m.code)) return { ok: false, id, error: 'Kode pasangan harus 6 angka' }
      return { ok: true, msg: { type: 'auth', id, code: m.code } }
    case 'status':
      return { ok: true, msg: { type: 'status', id } }
    case 'write': {
      if (typeof m.token !== 'string' || !TOKEN_RE.test(m.token)) return { ok: false, id, error: 'Token kartu tidak valid' }
      if (typeof m.url !== 'string' || m.url.length > MAX_URL_LENGTH) return { ok: false, id, error: 'URL tidak valid' }
      let u
      try { u = new URL(m.url) } catch { return { ok: false, id, error: 'URL tidak valid' } }
      if (u.protocol !== 'https:' && u.protocol !== 'http:') return { ok: false, id, error: 'URL harus http(s)' }
      // URL harus URL chip milik token ini (…/c/{token}), supaya konektor tidak dipakai menulis URL sembarang
      if (!new RegExp(`/c/${m.token}(?:[/?#]|$)`).test(m.url)) return { ok: false, id, error: 'URL tidak cocok dengan token kartu' }
      if (m.lock !== undefined && typeof m.lock !== 'boolean') return { ok: false, id, error: 'lock harus boolean' }
      return { ok: true, msg: { type: 'write', id, url: m.url, token: m.token, lock: m.lock === true } }
    }
    default:
      return { ok: false, id, error: 'Tipe pesan tidak dikenal' }
  }
}

/** Pesan galat transport/kartu → teks operator (bahasa Indonesia). */
export function bridgeErrorMessage(e) {
  if (e?.name === 'TagError') return e.message
  const text = String(e?.message ?? '')
  if (e?.name === 'ApduError' || /card.*(removed|not present)|SCARD_W_REMOVED|0x80100069|transmit/i.test(text)) {
    return 'Kartu terlepas atau tidak terbaca saat ditulis. Letakkan kartu diam di atas reader sampai selesai.'
  }
  return text || 'Gagal memproses kartu.'
}
