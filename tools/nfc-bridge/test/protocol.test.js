import assert from 'node:assert/strict'
import { test } from 'node:test'
import { bridgeErrorMessage, codesEqual, createAttemptLimiter, DEFAULT_PORT, generatePairingCode, isAllowedHost, isAllowedOrigin, parseOrigins, parsePort, validateMessage } from '../src/protocol.js'
import { pickReader } from '../src/pcsc.js'

test('origin: bawaan panel.localhost:8000 + NFC_BRIDGE_ORIGINS, lainnya ditolak', () => {
  const o = parseOrigins('https://panel.tapcard.id, rusak, ftp://x.id')
  assert.ok(isAllowedOrigin('http://panel.localhost:8000', o))
  assert.ok(isAllowedOrigin('https://panel.localhost:8000', o))
  assert.ok(isAllowedOrigin('https://panel.tapcard.id', o))
  for (const bad of ['https://evil.id', 'https://panel.tapcard.id.evil.id', 'http://panel.tapcard.id', 'null', '', undefined, 'https://panel.tapcard.id/x', 'ftp://x.id']) {
    assert.equal(isAllowedOrigin(bad, o), false, String(bad))
  }
})

test('host header wajib loopback + port (cegah DNS rebinding)', () => {
  assert.ok(isAllowedHost('127.0.0.1:47321', 47321))
  assert.ok(isAllowedHost('localhost:47321', 47321))
  assert.equal(isAllowedHost('evil.id:47321', 47321), false)
  assert.equal(isAllowedHost('127.0.0.1:1', 47321), false)
  assert.equal(isAllowedHost(undefined, 47321), false)
})

test('port bawaan 47321, env divalidasi', () => {
  assert.equal(parsePort(undefined), DEFAULT_PORT)
  assert.equal(parsePort('50000'), 50000)
  assert.throws(() => parsePort('80'), /tidak valid/)
  assert.throws(() => parsePort('abc'), /tidak valid/)
})

test('kode pasangan 6 angka & pembanding', () => {
  for (let i = 0; i < 20; i++) assert.match(generatePairingCode(), /^\d{6}$/)
  assert.ok(codesEqual('123456', '123456'))
  assert.equal(codesEqual('123456', '123457'), false)
  assert.equal(codesEqual('12345', '123456'), false)
})

test('pembatas percobaan: 5 gagal per menit lalu terbuka lagi', () => {
  let t = 0
  const l = createAttemptLimiter({ now: () => t })
  for (let i = 0; i < 5; i++) { assert.equal(l.blocked(), false); l.fail() }
  assert.equal(l.blocked(), true)
  t = 60_001
  assert.equal(l.blocked(), false)
})

test('validasi pesan', () => {
  assert.deepEqual(validateMessage('{"type":"status","id":1}'), { ok: true, msg: { type: 'status', id: 1 } })
  assert.equal(validateMessage('{"type":"auth","code":"123456"}').ok, true)
  assert.match(validateMessage('{"type":"auth","code":"12ab56"}').error, /6 angka/)
  const w = validateMessage(JSON.stringify({ type: 'write', id: 'a', url: 'https://tap.tapcard.id/c/Ab3dE5gH9k?k=1', token: 'Ab3dE5gH9k' }))
  assert.deepEqual(w.msg, { type: 'write', id: 'a', url: 'https://tap.tapcard.id/c/Ab3dE5gH9k?k=1', token: 'Ab3dE5gH9k', lock: false })
  assert.equal(validateMessage(JSON.stringify({ type: 'write', url: 'https://x.id/c/Ab3dE5gH9k', token: 'Ab3dE5gH9k', lock: true })).msg.lock, true)
  for (const [m, re] of [
    [{ type: 'write', url: 'https://x.id/lain', token: 'Ab3dE5gH9k' }, /tidak cocok/],
    [{ type: 'write', url: 'javascript:alert(1)//c/Ab3dE5gH9k', token: 'Ab3dE5gH9k' }, /http/],
    [{ type: 'write', url: 'https://x.id/c/Ab3dE5gH9k', token: 'pendek' }, /Token/],
    [{ type: 'write', url: 'https://x.id/c/Ab3dE5gH9k' + 'a'.repeat(300), token: 'Ab3dE5gH9k' }, /URL/],
    [{ type: 'write', url: 'https://x.id/c/Ab3dE5gH9k', token: 'Ab3dE5gH9k', lock: 'ya' }, /boolean/],
    [{ type: 'hapus' }, /tidak dikenal/],
    [{ type: 'status', id: { a: 1 } }, /id/],
  ]) assert.match(validateMessage(JSON.stringify(m)).error, re)
  assert.match(validateMessage('bukan json').error, /JSON/)
  assert.match(validateMessage('[1]').error, /tidak valid/)
})

test('pesan galat transport diterjemahkan', () => {
  assert.match(bridgeErrorMessage({ name: 'ApduError', message: 'x' }), /terlepas/)
  assert.match(bridgeErrorMessage(new Error('SCardTransmit error: 0x80100069')), /terlepas/)
  assert.equal(bridgeErrorMessage({ name: 'TagError', message: 'Kartu sudah dikunci' }), 'Kartu sudah dikunci')
})

test('pilih reader: PICC dipilih, SAM diabaikan, filter nama dihormati', () => {
  assert.equal(pickReader(null, 'ACS ACR1252 1S CL Reader SAM 0'), false)
  assert.equal(pickReader(null, 'ACS ACR1252 1S CL Reader PICC 0'), true)
  assert.equal(pickReader('ACS ACR1252 1S CL Reader PICC 0', 'ACS ACR122U 0'), false)
  assert.equal(pickReader('ACS ACR122U 0', 'ACS ACR1252 PICC 0'), true)
  assert.equal(pickReader(null, 'ACS ACR122U 0', 'acr1252'), false)
})
