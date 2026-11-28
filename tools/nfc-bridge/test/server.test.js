// Uji server WebSocket dengan transport tiruan (tanpa reader). Butuh paket `ws` (npm install di folder ini).
import assert from 'node:assert/strict'
import { EventEmitter } from 'node:events'
import { test } from 'node:test'
import { createBridgeServer } from '../src/server.js'
import { parseOrigins } from '../src/protocol.js'
import { fakeNtag } from './fakeTag.js'

let ws
try { ws = await import('ws') } catch { ws = null }
const PORT = 47399
const ORIGIN = 'http://panel.localhost:8000'

function fakeTransport() {
  const tag = fakeNtag()
  const em = new EventEmitter()
  let card = false
  return Object.assign(em, {
    tag,
    status: () => ({ reader: 'ACS ACR122U PICC 0', card }),
    place() { card = true; em.emit('card', { present: true, uid: '04A1B2C3D4E5F6' }) },
    waitForCard: () => new Promise((r) => em.once('card', r)),
    transmit: (a) => tag.transmit(a),
  })
}

function client(origin = ORIGIN) {
  const c = new ws.WebSocket(`ws://127.0.0.1:${PORT}`, { origin })
  const inbox = []
  const waiters = []
  c.on('message', (m) => {
    const msg = JSON.parse(m)
    const i = waiters.findIndex((w) => w.pred(msg))
    if (i >= 0) waiters.splice(i, 1)[0].resolve(msg)
    else inbox.push(msg)
  })
  c.next = (pred) => {
    const i = inbox.findIndex(pred)
    if (i >= 0) return Promise.resolve(inbox.splice(i, 1)[0])
    return new Promise((resolve) => waiters.push({ pred, resolve }))
  }
  c.sendJson = (m) => c.send(JSON.stringify(m))
  return c
}

test('server: origin asing ditolak, pasangan wajib, tulis → UID', { skip: !ws && 'paket ws belum terpasang' }, async () => {
  const transport = fakeTransport()
  const srv = createBridgeServer({ WebSocketServer: ws.WebSocketServer, port: PORT, origins: parseOrigins(''), pairingCode: '123456', transport })
  await new Promise((r) => srv.wss.on('listening', r))
  try {
    const evil = client('https://evil.id')
    await assert.rejects(new Promise((res, rej) => { evil.on('open', res); evil.on('error', rej) }), /403/)

    const c = client()
    await new Promise((r) => c.on('open', r))
    assert.equal((await c.next((m) => m.type === 'hello')).canLock, false)

    c.sendJson({ type: 'status', id: 1 })
    assert.equal((await c.next((m) => m.id === 1)).code, 'unpaired')

    c.sendJson({ type: 'auth', id: 2, code: '000000' })
    assert.equal((await c.next((m) => m.id === 2)).ok, false)

    c.sendJson({ type: 'auth', id: 3, code: '123456' })
    assert.equal((await c.next((m) => m.id === 3)).ok, true)
    const st = await c.next((m) => m.type === 'status')
    assert.equal(st.reader, 'ACS ACR122U PICC 0')
    assert.equal(st.card, false)

    const url = 'https://tap.tapcard.id/c/Ab3dE5gH9k?k=1'
    c.sendJson({ type: 'write', id: 4, url, token: 'Ab3dE5gH9k', lock: true })
    assert.match((await c.next((m) => m.id === 4 && m.type === 'result')).error, /--allow-lock/)

    c.sendJson({ type: 'write', id: 5, url, token: 'Ab3dE5gH9k' })
    assert.match((await c.next((m) => m.id === 5 && m.type === 'progress')).text, /Letakkan kartu/)
    transport.place()
    assert.equal((await c.next((m) => m.type === 'card')).present, true)
    const res = await c.next((m) => m.id === 5 && m.type === 'result')
    assert.deepEqual([res.ok, res.uid, res.locked], [true, '04A1B2C3D4E5F6', false])
    c.close()
  } finally {
    await srv.close()
  }
})
