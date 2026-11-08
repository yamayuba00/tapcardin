// Realtime lewat Laravel Reverb (protokol Pusher) + Laravel Echo. Dimuat dinamis hanya di halaman yang memakainya,
// jadi halaman publik (bio link) tidak ikut membawa pustaka WebSocket. Alamat & kunci dari config server (SpaController).

/**
 * Buat koneksi Echo ke Reverb. Channel privat diotorisasi dengan sesi host saat ini (app., panel.) + token CSRF.
 * @param {{key:string, host:string, port:number, tls:boolean}} cfg
 * @param {(state: string) => void} [onState] connecting | connected | unavailable | failed | disconnected
 */
async function createEcho(cfg, onState) {
  const [{ default: Echo }, { default: Pusher }] = await Promise.all([import('laravel-echo'), import('pusher-js')])
  const csrf = () => {
    const xsrf = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)
    return xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf[1]) } : { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' }
  }
  const echo = new Echo({
    broadcaster: 'reverb',
    Pusher,
    key: cfg.key,
    wsHost: cfg.host,
    wsPort: cfg.port,
    wssPort: cfg.port,
    forceTLS: cfg.tls,
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel) => ({
      authorize: (socketId, callback) => {
        fetch('/api/broadcasting/auth', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrf() },
          body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
        })
          .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`auth ${r.status}`))))
          .then((data) => callback(null, data))
          .catch((e) => callback(e, null))
      },
    }),
  })
  if (onState) echo.connector.pusher.connection.bind('state_change', ({ current }) => onState(current))
  return echo
}

/**
 * Hubungkan ke Reverb dan dengarkan channel privat pengguna.
 * @param {(payload: {id:number, type:string, tenant:?string, data:object}) => void} onNotification
 * @returns {Promise<() => void>} pemutus koneksi
 */
export async function connectRealtime(cfg, userId, onNotification, onState) {
  const echo = await createEcho(cfg, onState)
  echo.private(`user.${userId}`).listen('.notification', onNotification)
  return () => echo.disconnect()
}

/**
 * Panel: setiap hasil cek uptime (channel privat staf `platform.uptime`).
 * @param {(payload: {monitor: object, check: object, change: ?{from:string,to:string,reason:?string}}) => void} onChecked
 * @returns {Promise<() => void>}
 */
export async function connectUptime(cfg, onChecked, onState) {
  const echo = await createEcho(cfg, onState)
  echo.private('platform.uptime').listen('.checked', onChecked)
  return () => echo.disconnect()
}

/**
 * Halaman status publik: sinyal "data berubah, muat ulang" (channel publik `status`, tanpa login).
 * @returns {Promise<() => void>}
 */
export async function connectStatusPage(cfg, onUpdated, onState) {
  const echo = await createEcho(cfg, onState)
  echo.channel('status').listen('.updated', onUpdated)
  return () => echo.disconnect()
}
