import assert from 'node:assert/strict'
import { test } from 'node:test'
import { encodeUriRecord, encodeUrlTlv, ndefTlv, parseUriRecord, parseUrlFromTlv, splitUriPrefix } from '../src/ndef.js'

test('prefiks https:// dipadatkan jadi kode 0x04, https://www. jadi 0x02', () => {
  assert.deepEqual(splitUriPrefix('https://tap.tapcard.id/c/Ab3dE5gH9k'), [0x04, 'tap.tapcard.id/c/Ab3dE5gH9k'])
  assert.deepEqual(splitUriPrefix('https://www.contoh.id/'), [0x02, 'contoh.id/'])
  assert.deepEqual(splitUriPrefix('http://tap.localhost:8000/c/x'), [0x03, 'tap.localhost:8000/c/x'])
  assert.deepEqual(splitUriPrefix('custom:abc'), [0x00, 'custom:abc'])
})

test('record URI pendek berbentuk D1 01 <len> 55 <kode> <sisa>', () => {
  const rec = encodeUriRecord('https://a.id')
  assert.equal(rec.toString('hex'), 'd1' + '01' + '05' + '55' + '04' + Buffer.from('a.id').toString('hex'))
})

test('TLV: 03 <len> <pesan> FE; panjang ≥ 255 memakai 03 FF hi lo', () => {
  const tlv = encodeUrlTlv('https://a.id')
  assert.equal(tlv[0], 0x03)
  assert.equal(tlv[1], tlv.length - 3)
  assert.equal(tlv.at(-1), 0xfe)
  const long = ndefTlv(Buffer.alloc(300, 1))
  assert.deepEqual([...long.subarray(0, 4)], [0x03, 0xff, 0x01, 0x2c])
  assert.equal(long.length, 300 + 5)
})

test('record panjang (payload ≥ 256) memakai header C1 & panjang 4 byte, dan bisa dibaca ulang', () => {
  const url = 'https://contoh.id/' + 'a'.repeat(300)
  const rec = encodeUriRecord(url)
  assert.equal(rec[0], 0xc1)
  assert.equal(parseUriRecord(rec), url)
  assert.equal(parseUrlFromTlv(encodeUrlTlv(url)), url)
})

test('parse melewati TLV Null & Lock Control, null bila tidak ada NDEF', () => {
  const url = 'https://tap.tapcard.id/c/Ab3dE5gH9k?k=XYZ'
  const data = Buffer.concat([Buffer.from([0x00, 0x01, 0x03, 0xa0, 0x0c, 0x34]), encodeUrlTlv(url), Buffer.alloc(8)])
  assert.equal(parseUrlFromTlv(data), url)
  assert.equal(parseUrlFromTlv(Buffer.from([0x03, 0x00, 0xfe])), null)
  assert.equal(parseUrlFromTlv(Buffer.from([0xfe])), null)
  assert.equal(parseUrlFromTlv(Buffer.from([0x03, 0x20, 0xd1])), null) // terpotong
})

test('URL UTF-8 tetap utuh', () => {
  const url = 'https://contoh.id/kartu/é'
  assert.equal(parseUrlFromTlv(encodeUrlTlv(url)), url)
})
