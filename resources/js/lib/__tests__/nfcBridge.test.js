import assert from 'node:assert/strict'
import { test } from 'node:test'
import { BRIDGE_DEFAULT_PORT, PAIR_KEY, PORT_KEY, bridgePort, bridgeStatusText, createBridgeClient, safeStorage } from '../nfcBridge.js'

class MockWS {
  static last = null
  constructor(url) {
    this.url = url
    this.readyState = 0
    this.sent = []
    MockWS.last = this
  }
  send(d) { this.sent.push(JSON.parse(d)) }
  close() { this.readyState = 3 }
  open() { this.readyState = 1; this.onopen?.() }
  recv(m) { this.onmessage?.({ data: JSON.stringify(m) }) }
  drop() { this.readyState = 3; this.onclose?.() }
}

function memStorage(init = {}) {
  const m = new Map(Object.entries(init))
  return safeStorage({ getItem: (k) => m.get(k) ?? null, setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k) })
}

function setup(init) {
  const storage = memStorage(init)
  const changes = []
  const c = createBridgeClient({ WebSocketImpl: MockWS, storage, onChange: (s) => changes.push(s), reconnectMs: 5 })
  c.connect()
  return { c, ws: MockWS.last, storage, changes }
}

test('URL port bawaan 127.0.0.1:47321, bisa diganti lewat localStorage', () => {
  assert.equal(bridgePort(memStorage()), BRIDGE_DEFAULT_PORT)
  assert.equal(bridgePort(memStorage({ [PORT_KEY]: '50000' })), 50000)
  assert.equal(bridgePort(memStorage({ [PORT_KEY]: 'x' })), BRIDGE_DEFAULT_PORT)
  const { c, ws } = setup()
  assert.equal(ws.url, 'ws://127.0.0.1:47321')
  c.close()
})

test('localStorage yang melempar tidak menjatuhkan klien', () => {
  const boom = () => { throw new Error('SecurityError') }
  const s = safeStorage({ getItem: boom, setItem: boom, removeItem: boom })
  assert.equal(s.get('a'), null)
  assert.doesNotThrow(() => { s.set('a', '1'); s.remove('a') })
})

test('teks status: tidak terhubung → terhubung → reader → kartu', () => {
  assert.equal(bridgeStatusText({ connected: false }), 'Tidak terhubung')
  assert.match(bridgeStatusText({ connected: true, paired: false }), /kode pasangan/)
  assert.match(bridgeStatusText({ connected: true, paired: true, reader: null }), /reader belum/)
  assert.equal(bridgeStatusText({ connected: true, paired: true, reader: 'ACR122U' }), 'Reader: ACR122U')
  assert.equal(bridgeStatusText({ connected: true, paired: true, reader: 'ACR122U', card: true }), 'Reader: ACR122U · Kartu terdeteksi')
})

test('tanpa kode tersimpan: minta pasangan; kode benar disimpan', () => {
  const { c, ws, storage } = setup()
  ws.open()
  ws.recv({ type: 'hello', version: 1, canLock: false })
  assert.equal(c.state.needsPairing, true)
  assert.equal(ws.sent.length, 0)
  c.pair('12ab')
  assert.match(c.state.error, /6 angka/)
  c.pair(' 123456 ')
  assert.deepEqual(ws.sent.at(-1), { type: 'auth', code: '123456' })
  ws.recv({ type: 'auth', ok: true })
  ws.recv({ type: 'status', reader: 'ACS ACR122U', card: false, canLock: false })
  assert.equal(storage.get(PAIR_KEY), '123456')
  assert.equal(c.state.paired, true)
  assert.equal(c.state.reader, 'ACS ACR122U')
  c.close()
})

test('kode tersimpan dikirim otomatis; ditolak → dihapus & minta ulang', () => {
  const { c, ws, storage } = setup({ [PAIR_KEY]: '654321' })
  ws.open()
  ws.recv({ type: 'hello', canLock: true })
  assert.deepEqual(ws.sent[0], { type: 'auth', code: '654321' })
  ws.recv({ type: 'auth', ok: false, error: 'Kode pasangan salah.' })
  assert.equal(storage.get(PAIR_KEY), null)
  assert.equal(c.state.needsPairing, true)
  assert.equal(c.state.error, 'Kode pasangan salah.')
  assert.equal(c.state.canLock, true)
  c.close()
})

test('event reader & kartu memperbarui status', () => {
  const { c, ws } = setup({ [PAIR_KEY]: '111111' })
  ws.open()
  ws.recv({ type: 'hello' })
  ws.recv({ type: 'auth', ok: true })
  ws.recv({ type: 'reader', name: 'ACR1252 PICC' })
  ws.recv({ type: 'card', present: true, uid: '04AA' })
  assert.equal(c.state.card, true)
  ws.recv({ type: 'card', present: false })
  assert.equal(c.state.card, false)
  ws.recv({ type: 'card', present: true })
  ws.recv({ type: 'reader', name: null })
  assert.deepEqual([c.state.reader, c.state.card], [null, false])
  c.close()
})

test('write: progress diteruskan, result → UID; galat → Error berbahasa Indonesia', async () => {
  const { c, ws } = setup({ [PAIR_KEY]: '111111' })
  ws.open()
  ws.recv({ type: 'hello' })
  ws.recv({ type: 'auth', ok: true })
  const status = []
  const p = c.write('https://tap.x/c/Ab3dE5gH9k', 'Ab3dE5gH9k', { lock: true, onStatus: (t) => status.push(t) })
  const sent = ws.sent.at(-1)
  assert.deepEqual({ ...sent, id: undefined }, { type: 'write', id: undefined, url: 'https://tap.x/c/Ab3dE5gH9k', token: 'Ab3dE5gH9k', lock: true })
  ws.recv({ type: 'progress', id: sent.id, text: 'Menulis… tahan kartu' })
  ws.recv({ type: 'result', id: sent.id, ok: true, uid: '04A1B2C3D4E5F6' })
  assert.equal(await p, '04A1B2C3D4E5F6')
  assert.deepEqual(status, ['Menulis… tahan kartu'])

  const q = c.write('https://tap.x/c/Ab3dE5gH9k', 'Ab3dE5gH9k')
  assert.equal(ws.sent.at(-1).lock, false)
  ws.recv({ type: 'result', id: ws.sent.at(-1).id, ok: false, error: 'Kartu sudah dikunci permanen' })
  await assert.rejects(q, /sudah dikunci/)
  c.close()
})

test('write ditolak bila belum terhubung/dipasangkan; koneksi putus menggagalkan write & menyambung ulang', async () => {
  const { c, ws } = setup()
  await assert.rejects(c.write('u', 't'), /tidak terhubung/)
  ws.open()
  ws.recv({ type: 'hello' })
  await assert.rejects(c.write('u', 't'), /belum dipasangkan/)
  c.pair('123456')
  ws.recv({ type: 'auth', ok: true })
  const p = c.write('https://tap.x/c/Ab3dE5gH9k', 'Ab3dE5gH9k')
  ws.drop()
  await assert.rejects(p, /terputus/)
  assert.equal(c.state.connected, false)
  await new Promise((r) => setTimeout(r, 20))
  assert.notEqual(MockWS.last, ws, 'menyambung ulang otomatis')
  c.close()
})
