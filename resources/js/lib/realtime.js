// Realtime lewat Laravel Reverb (protokol Pusher) + Laravel Echo. Dimuat dinamis hanya di aplikasi yang login,
// jadi halaman publik (bio link) tidak ikut membawa pustaka WebSocket. Alamat & kunci dari config server (SpaController).

/**
 * Hubungkan ke Reverb dan dengarkan channel privat pengguna.
 * @param {{key:string, host:string, port:number, tls:boolean}} cfg
 * @param {string} userId public_id pengguna
 * @param {(payload: {id:number, type:string, tenant:?string, data:object}) => void} onNotification
 * @param {(state: string) => void} onState connecting | connected | unavailable | failed | disconnected
 * @returns {Promise<() => void>} pemutus koneksi
 */
export async function connectRealtime(cfg, userId, onNotification, onState) {
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
    // otorisasi channel privat memakai sesi app. (cookie) + token CSRF terbaru
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
  echo.connector.pusher.connection.bind('state_change', ({ current }) => onState(current))
  echo.private(`user.${userId}`).listen('.notification', onNotification)
  return () => echo.disconnect()
}
