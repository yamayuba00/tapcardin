// Server WebSocket konektor: hanya 127.0.0.1, origin di allowlist, wajib kode pasangan sebelum perintah apa pun.
import { writeUrlToTag, TagError } from './tag.js'
import { BIND_HOST, bridgeErrorMessage, codesEqual, createAttemptLimiter, isAllowedHost, isAllowedOrigin, validateMessage } from './protocol.js'

export const PROTOCOL_VERSION = 1
const AUTH_TIMEOUT_MS = 120_000

/**
 * @param {object} o
 * @param {typeof import('ws').WebSocketServer} o.WebSocketServer
 * @param {number} o.port
 * @param {Set<string>} o.origins
 * @param {string} o.pairingCode
 * @param {{ status(): {reader: string|null, card: boolean}, waitForCard(ms: number): Promise<void>, transmit(apdu: Buffer): Promise<Buffer>, on(ev: string, fn: Function): void }} o.transport
 */
export function createBridgeServer({ WebSocketServer, port, origins, pairingCode, transport, allowLock = false, log = () => {}, limiter = createAttemptLimiter(), cardTimeoutMs = 20_000 }) {
  const wss = new WebSocketServer({
    host: BIND_HOST,
    port,
    maxPayload: 4096,
    verifyClient: ({ origin, req }, cb) => {
      if (!isAllowedHost(req.headers.host, port)) { log(`tolak: host ${req.headers.host}`); return cb(false, 403) }
      if (!isAllowedOrigin(origin, origins)) { log(`tolak: origin ${origin ?? '(kosong)'}`); return cb(false, 403) }
      if (req.socket.remoteAddress !== '127.0.0.1' && req.socket.remoteAddress !== '::ffff:127.0.0.1') return cb(false, 403)
      cb(true)
    },
  })
  const clients = new Set()
  let busy = false

  const send = (ws, m) => { if (ws.readyState === 1) ws.send(JSON.stringify(m)) }
  const broadcast = (m) => { for (const ws of clients) if (ws.authed) send(ws, m) }
  const statusMsg = (id) => ({ type: 'status', id, ...transport.status(), canLock: allowLock })

  transport.on('reader', (name) => broadcast({ type: 'reader', name: name ?? null }))
  transport.on('card', (c) => broadcast({ type: 'card', present: !!c?.present, uid: c?.present ? c.uid ?? null : null }))

  wss.on('connection', (ws, req) => {
    ws.authed = false
    clients.add(ws)
    log(`klien terhubung dari ${req.headers.origin}`)
    const authTimer = setTimeout(() => { if (!ws.authed) ws.close(4001, 'unpaired') }, AUTH_TIMEOUT_MS)
    ws.on('close', () => { clients.delete(ws); clearTimeout(authTimer) })
    send(ws, { type: 'hello', version: PROTOCOL_VERSION, canLock: allowLock })

    ws.on('message', async (raw) => {
      const v = validateMessage(raw)
      if (!v.ok) return send(ws, { type: 'error', id: v.id, error: v.error })
      const m = v.msg
      if (m.type === 'auth') {
        if (limiter.blocked()) return send(ws, { type: 'auth', id: m.id, ok: false, error: 'Terlalu banyak percobaan. Tunggu 1 menit.' })
        if (!codesEqual(m.code, pairingCode)) {
          limiter.fail()
          return send(ws, { type: 'auth', id: m.id, ok: false, error: 'Kode pasangan salah.' })
        }
        ws.authed = true
        clearTimeout(authTimer)
        send(ws, { type: 'auth', id: m.id, ok: true })
        return send(ws, statusMsg())
      }
      if (!ws.authed) return send(ws, { type: 'error', id: m.id, code: 'unpaired', error: 'Konektor belum dipasangkan.' })
      if (m.type === 'status') return send(ws, statusMsg(m.id))
      // write
      const fail = (error) => send(ws, { type: 'result', id: m.id, ok: false, error })
      if (m.lock && !allowLock) return fail('Penguncian chip dinonaktifkan di konektor. Jalankan konektor dengan --allow-lock.')
      if (busy) return fail('Konektor sedang menulis kartu lain.')
      busy = true
      const progress = (text) => send(ws, { type: 'progress', id: m.id, text })
      try {
        if (!transport.status().reader) throw new TagError('no_reader', 'Reader NFC tidak ditemukan. Colok reader USB lalu coba lagi.')
        if (!transport.status().card) {
          progress('Letakkan kartu di atas reader…')
          await transport.waitForCard(cardTimeoutMs)
        }
        const res = await writeUrlToTag((apdu) => transport.transmit(apdu), { url: m.url, lock: m.lock, onStatus: progress })
        log(`tulis OK ${m.token} UID ${res.uid}${res.locked ? ' (dikunci)' : ''}`)
        send(ws, { type: 'result', id: m.id, ok: true, ...res })
      } catch (e) {
        log(`tulis gagal ${m.token}: ${e?.message}`)
        fail(bridgeErrorMessage(e))
      } finally {
        busy = false
      }
    })
  })

  return {
    wss,
    close: () => new Promise((r) => { for (const ws of clients) ws.terminate(); wss.close(() => r()) }),
  }
}
