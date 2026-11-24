import { config } from '@/config'

// Klien HTTP (fetch) dengan CSRF & error seragam BSD §8.1 `{error:{code,message,fields}}`.

/** Header kunci API untuk setiap pemanggilan dari halaman web (kosong bila tidak diatur, mis. host tap.). */
export const apiKeyHeader = () => (config.apiKey ? { 'X-Tap-Key': config.apiKey } : {})

function readCookie(name) {
  const m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'))
  return m ? decodeURIComponent(m[1]) : null
}

export class ApiError extends Error {
  constructor(message, { status, code, fields, data } = {}) {
    super(message)
    this.status = status
    this.code = code
    this.fields = fields || {}
    this.data = data
  }
}

/** Dipanggil saat API mengembalikan 401 (sesi habis) — diset oleh router. */
let onUnauthenticated = () => {}
export const setUnauthenticatedHandler = (fn) => (onUnauthenticated = fn)

/**
 * @param {'GET'|'POST'|'PUT'|'PATCH'|'DELETE'} method
 * @param {string} url
 * @param {object|FormData} [body]
 */
export async function request(method, url, body) {
  const isForm = typeof FormData !== 'undefined' && body instanceof FormData
  const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
  // Laravel memperbarui cookie XSRF-TOKEN di setiap respons, jadi token tetap valid setelah login/logout.
  const xsrf = readCookie('XSRF-TOKEN')
  if (xsrf) headers['X-XSRF-TOKEN'] = xsrf
  else headers['X-CSRF-TOKEN'] = document.querySelector('meta[name="csrf-token"]')?.content
  Object.assign(headers, apiKeyHeader())
  if (body && !isForm) headers['Content-Type'] = 'application/json'

  const res = await fetch(url, {
    method,
    credentials: 'same-origin',
    headers,
    body: body ? (isForm ? body : JSON.stringify(body)) : undefined,
  })
  const data = res.status === 204 ? null : await res.json().catch(() => null)

  if (!res.ok) {
    if (res.status === 401) onUnauthenticated(data?.error?.code)
    const message = data?.error?.message
      || (res.status === 419 ? 'Sesi berakhir, muat ulang halaman.' : res.status === 429 ? 'Terlalu banyak permintaan.' : 'Permintaan gagal')
    throw new ApiError(message, { status: res.status, code: data?.error?.code, fields: data?.error?.fields, data })
  }
  return data
}

export const http = {
  get: (url, params) => request('GET', params ? `${url}?${toQuery(params)}` : url),
  post: (url, body) => request('POST', url, body),
  put: (url, body) => request('PUT', url, body),
  patch: (url, body) => request('PATCH', url, body),
  del: (url, body) => request('DELETE', url, body),
}

export function toQuery(params) {
  return new URLSearchParams(Object.entries(params).filter(([, v]) => v !== '' && v != null)).toString()
}

/**
 * Unduhan CSV/PDF dari API. Lewat fetch (bukan tautan) agar header kunci ikut terkirim; nama file dari Content-Disposition.
 */
export async function download(url, fallbackName) {
  const res = await fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', ...apiKeyHeader() } })
  if (!res.ok) {
    const data = await res.json().catch(() => null)
    throw new ApiError(data?.error?.message ?? 'Unduhan gagal.', { status: res.status, code: data?.error?.code })
  }
  const m = (res.headers.get('content-disposition') || '').match(/filename="?([^";]+)"?/)
  const href = URL.createObjectURL(await res.blob())
  const a = Object.assign(document.createElement('a'), { href, download: m ? m[1] : fallbackName })
  document.body.append(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(href), 1000)
}
