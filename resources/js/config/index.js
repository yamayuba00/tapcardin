// Konfigurasi runtime dari shell (data-config) + konteks host (TRD §4.6).
const el = document.getElementById('app')
const raw = el?.dataset.config ? JSON.parse(el.dataset.config) : {}

/** @type {{baseDomain: string, resolverUrl: string, appUrl: string, adminUrl: string, marketingUrl: string, googleEnabled: boolean}} */
export const config = {
  baseDomain: 'localhost',
  resolverUrl: '',
  appUrl: '',
  adminUrl: '',
  marketingUrl: '',
  googleEnabled: false,
  /** @type {null | {code: string, password: string, accounts: {email: string, name: string, staff: boolean}[]}} hanya di APP_ENV=local */
  devLogin: null,
  /** Data awal yang disisipkan server untuk halaman sensitif kecepatan (mis. `card` di /c/{token}). */
  initial: null,
  ...raw,
}

/** Ambil data awal sekali saja (navigasi berikutnya harus memanggil API agar tap tercatat lagi). */
export function takeInitial(key) {
  const value = config.initial?.[key] ?? null
  if (config.initial) delete config.initial[key]
  return value
}

/** 'public' (tap.), 'admin' (panel. — panel pemilik), 'marketing' (domain utama), atau 'workspace' (app. & {slug}.) */
export function hostContext(hostname = window.location.hostname, base = config.baseDomain) {
  if (hostname === base) return 'marketing'
  const sub = hostname.endsWith('.' + base) ? hostname.slice(0, -(base.length + 1)) : hostname.split('.')[0]
  if (sub === 'tap') return 'public'
  if (sub === 'panel' || sub === 'admin' || (config.adminUrl && hostname === new URL(config.adminUrl).hostname)) return 'admin'
  return 'workspace'
}

export const context = hostContext()
