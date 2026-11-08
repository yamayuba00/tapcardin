import { http } from './client'

/** Endpoint publik di tap.{base} (BSD §8.2). */
export const publicApi = {
  /** Mencatat tap; `src` = nfc | qr | code | link. */
  resolve: (token, src) => http.get(`/api/c/${token}`, src ? { src } : undefined),
  connect: (url, form) => http.post(url, form),
  shortCode: (code) => http.post('/api/shortcode', { code }),
  handle: (handle) => http.get(`/api/handle/${handle}`),
  /** status. — ringkasan halaman status publik */
  status: () => http.get('/api/status'),
}

/** Domain utama (situs marketing). */
export const marketingApi = {
  /** Template kartu default, preset bio link, contoh profil, paket. */
  showcase: () => http.get('/api/showcase'),
  /** status layanan langsung (sama dengan status.{base}) */
  status: () => http.get('/api/status'),
}
