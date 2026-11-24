// Konfigurasi runtime dari shell (data-config) + konteks host (TRD §4.6).
// di render server (SSR landing) tidak ada document: config diisi oleh entri SSR
const el = typeof document === 'undefined' ? null : document.getElementById('app')
const raw = el?.dataset.config ? JSON.parse(el.dataset.config) : {}

/** @type {{baseDomain: string, resolverUrl: string, appUrl: string, adminUrl: string, marketingUrl: string, googleEnabled: boolean}} */
export const config = {
  baseDomain: 'localhost',
  resolverUrl: '',
  appUrl: '',
  adminUrl: '',
  marketingUrl: '',
  /** halaman status publik (status.{base}) */
  statusUrl: '',
  googleEnabled: false,
  /** kunci API (header X-Tap-Key), dari shell server */
  apiKey: null,
  /** @type {null | {name: string, logo: string|null}} subdomain / domain kustom perusahaan: merek halaman login */
  tenant: null,
  /** @type {null | {code: string, password: string, accounts: {email: string, name: string, staff: boolean}[]}} hanya di APP_ENV=local */
  devLogin: null,
  /** Data awal yang disisipkan server untuk halaman sensitif kecepatan (mis. `card` di /c/{token}). */
  initial: null,
  /** @type {null | {status: number}} galat HTTP halaman dari server → ErrorScreen */
  error: null,
  ...raw,
}

/** Ambil data awal sekali saja (navigasi berikutnya harus memanggil API agar tap tercatat lagi). */
export function takeInitial(key) {
  const value = config.initial?.[key] ?? null
  if (config.initial) delete config.initial[key]
  return value
}

/** 'public' (tap.), 'admin' (panel. — panel pemilik), 'status' (status. — halaman status), 'webhooks' (wbh. — dokumentasi developer), 'marketing' (domain utama), atau 'workspace' (app. & {slug}.) */
export function hostContext(hostname = typeof window === 'undefined' ? config.baseDomain : window.location.hostname, base = config.baseDomain) {
  if (hostname === base) return 'marketing'
  const sub = hostname.endsWith('.' + base) ? hostname.slice(0, -(base.length + 1)) : hostname.split('.')[0]
  if (sub === 'tap') return 'public'
  if (sub === 'status') return 'status'
  if (sub === 'wbh') return 'webhooks'
  if (sub === 'panel' || sub === 'admin' || (config.adminUrl && hostname === new URL(config.adminUrl).hostname)) return 'admin'
  return 'workspace'
}

export const context = hostContext()
