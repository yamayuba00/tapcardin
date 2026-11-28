// Klien konektor NFC USB (tools/nfc-bridge) lewat WebSocket ws://127.0.0.1:<port>. Murni JS, bisa diuji dengan WebSocket tiruan.
export const BRIDGE_DEFAULT_PORT = 47321
export const PAIR_KEY = 'tapcard.nfcBridgePairing'
export const PORT_KEY = 'tapcard.nfcBridgePort'

/** localStorage yang tidak pernah melempar (mode privat, situs diblokir). */
export function safeStorage(backend) {
  const s = () => backend ?? globalThis.localStorage
  return {
    get: (k) => { try { return s()?.getItem(k) ?? null } catch { return null } },
    set: (k, v) => { try { s()?.setItem(k, v) } catch { /* abaikan */ } },
    remove: (k) => { try { s()?.removeItem(k) } catch { /* abaikan */ } },
  }
}

export function bridgePort(storage = safeStorage()) {
  const n = Number(storage.get(PORT_KEY))
  return Number.isInteger(n) && n >= 1024 && n <= 65535 ? n : BRIDGE_DEFAULT_PORT
}

/** Teks status konektor untuk operator. */
export function bridgeStatusText(s) {
  if (!s.connected) return 'Tidak terhubung'
  if (!s.paired) return 'Terhubung · masukkan kode pasangan'
  if (!s.reader) return 'Terhubung · reader belum dicolok'
  return s.card ? `Reader: ${s.reader} · Kartu terdeteksi` : `Reader: ${s.reader}`
}

/**
 * @param {{ WebSocketImpl?: typeof WebSocket, storage?: ReturnType<typeof safeStorage>, port?: number,
 *   onChange?: (state: object) => void, reconnectMs?: number, writeTimeoutMs?: number }} [o]
 */
export function createBridgeClient({ WebSocketImpl = globalThis.WebSocket, storage = safeStorage(), port, onChange = () => {}, reconnectMs = 3000, writeTimeoutMs = 45000 } = {}) {
  const url = `ws://127.0.0.1:${port ?? bridgePort(storage)}`
  const state = { connected: false, paired: false, needsPairing: false, reader: null, card: false, canLock: false, error: '' }
  const pending = new Map()
  let ws = null
  let stopped = false
  let timer = null
  let seq = 0
  let triedCode = null
  let failures = 0 // jeda sambung ulang naik bertahap (3 s → maks 30 s) agar konsol tidak dibanjiri galat koneksi

  const emit = (patch) => { Object.assign(state, patch); onChange({ ...state }) }
  const send = (m) => { if (ws && ws.readyState === 1) ws.send(JSON.stringify(m)) }

  function rejectAll(message) {
    for (const p of pending.values()) { clearTimeout(p.timer); p.reject(new Error(message)) }
    pending.clear()
  }

  function onMessage(ev) {
    let m
    try { m = JSON.parse(ev.data) } catch { return }
    switch (m.type) {
      case 'hello': {
        const code = storage.get(PAIR_KEY)
        emit({ canLock: !!m.canLock, needsPairing: !code, error: '' })
        if (code) { triedCode = code; send({ type: 'auth', code }) }
        break
      }
      case 'auth':
        if (m.ok) {
          if (triedCode) storage.set(PAIR_KEY, triedCode)
          emit({ paired: true, needsPairing: false, error: '' })
        } else {
          storage.remove(PAIR_KEY)
          emit({ paired: false, needsPairing: true, error: m.error || 'Kode pasangan salah.' })
        }
        triedCode = null
        break
      case 'status':
        emit({ reader: m.reader ?? null, card: !!m.card, canLock: !!m.canLock })
        break
      case 'reader':
        emit({ reader: m.name ?? null, card: m.name ? state.card : false })
        break
      case 'card':
        emit({ card: !!m.present })
        break
      case 'progress':
        pending.get(m.id)?.onStatus(m.text)
        break
      case 'result': {
        const p = pending.get(m.id)
        if (!p) break
        pending.delete(m.id)
        clearTimeout(p.timer)
        if (m.ok) p.resolve(m.uid)
        else p.reject(new Error(m.error || 'Gagal menulis kartu.'))
        break
      }
      case 'error':
        if (m.code === 'unpaired') emit({ paired: false, needsPairing: true })
        if (pending.has(m.id)) {
          const p = pending.get(m.id)
          pending.delete(m.id)
          clearTimeout(p.timer)
          p.reject(new Error(m.error))
        }
        break
    }
  }

  function connect() {
    stopped = false
    if (ws || !WebSocketImpl) return
    try {
      ws = new WebSocketImpl(url)
    } catch {
      ws = null
      schedule()
      return
    }
    ws.onopen = () => { failures = 0; emit({ connected: true, error: '' }) }
    ws.onmessage = onMessage
    ws.onerror = () => {}
    ws.onclose = () => {
      ws = null
      rejectAll('Koneksi ke konektor terputus.')
      emit({ connected: false, paired: false, reader: null, card: false })
      schedule()
    }
  }

  function schedule() {
    if (stopped || timer) return
    const delay = Math.min(reconnectMs * 2 ** failures, 30000)
    failures++
    timer = setTimeout(() => { timer = null; connect() }, delay)
  }

  return {
    url,
    get state() { return { ...state } },
    connect,
    /** Kirim kode pasangan dari konsol konektor; disimpan di localStorage bila benar. */
    pair(code) {
      const c = String(code ?? '').trim()
      if (!/^\d{6}$/.test(c)) { emit({ error: 'Kode pasangan harus 6 angka.' }); return }
      triedCode = c
      send({ type: 'auth', code: c })
    },
    /** Lupakan kode tersimpan (mis. setelah konektor --reset-pairing). */
    unpair() {
      storage.remove(PAIR_KEY)
      emit({ paired: false, needsPairing: true })
    },
    refresh() { send({ type: 'status' }) },
    /**
     * Tulis → verifikasi → (opsional) kunci di konektor. Menghasilkan UID chip.
     * @returns {Promise<string>}
     */
    write(cardUrl, token, { lock = false, onStatus = () => {} } = {}) {
      if (!state.connected) return Promise.reject(new Error('Konektor NFC tidak terhubung. Jalankan konektor di PC ini.'))
      if (!state.paired) return Promise.reject(new Error('Konektor belum dipasangkan. Masukkan kode pasangan.'))
      const id = `w${++seq}`
      return new Promise((resolve, reject) => {
        const t = setTimeout(() => { pending.delete(id); reject(new Error('Konektor tidak menjawab. Coba lagi.')) }, writeTimeoutMs)
        pending.set(id, { resolve, reject, onStatus, timer: t })
        send({ type: 'write', id, url: cardUrl, token, lock: lock === true })
      })
    },
    close() {
      stopped = true
      clearTimeout(timer)
      timer = null
      rejectAll('Koneksi ditutup.')
      const w = ws
      ws = null
      if (w) { w.onclose = null; try { w.close() } catch { /* abaikan */ } }
      emit({ connected: false, paired: false, reader: null, card: false })
    },
  }
}
