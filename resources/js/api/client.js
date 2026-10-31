// Klien HTTP (fetch) dengan CSRF & error seragam BSD §8.1 `{error:{code,message,fields}}`.

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
