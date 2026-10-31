// Service worker TapCard (aplikasi app./workspace). Prinsip:
//  - API, tap kartu (/c/, /q/), webhook, dan login TIDAK pernah disentuh — selalu ke server (data & pencatatan tap).
//  - Navigasi halaman: network-first; bila offline tampilkan /offline.html. Shell HTML tidak di-cache (berisi CSRF).
//  - Aset Vite ber-hash, font, dan ikon: cache-first (nama file berubah tiap rilis, aman di-cache lama).
const VERSION = 'tapcard-v1'
const STATIC = `${VERSION}-static`
const PRECACHE = ['/offline.html', '/icons/icon-192.png', '/icons/icon-512.png']
const BYPASS = [/^\/api\//, /^\/c\//, /^\/q\//, /^\/webhooks\//, /^\/auth\//, /^\/sanctum\//]

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(STATIC).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()))
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  )
})

self.addEventListener('fetch', (event) => {
  const req = event.request
  const url = new URL(req.url)
  if (req.method !== 'GET' || url.origin !== self.location.origin || BYPASS.some((r) => r.test(url.pathname))) return

  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(() => caches.match('/offline.html')))
    return
  }
  if (/^\/(build|fonts|icons)\//.test(url.pathname)) {
    event.respondWith(
      caches.match(req).then((hit) => hit || fetch(req).then((res) => {
        if (res.ok) {
          const copy = res.clone()
          caches.open(STATIC).then((c) => c.put(req, copy))
        }
        return res
      })),
    )
  }
})
