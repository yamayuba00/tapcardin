import assert from 'node:assert/strict'
import { test } from 'node:test'
import { encodeUrlTlv, parseUrlFromTlv } from '../src/ndef.js'
import { assertFits, lockPlan, parseCc, splitPages, writeUrlToTag } from '../src/tag.js'
import { fakeNtag } from './fakeTag.js'

const URL_OK = 'https://tap.tapcard.id/c/Ab3dE5gH9k?k=abcdef0123456789'

test('CC → model & kapasitas NTAG213/215/216', () => {
  assert.deepEqual([0x12, 0x3e, 0x6d].map((s) => parseCc([0xe1, 0x10, s, 0]).capacity), [144, 496, 872])
  assert.deepEqual([0x12, 0x3e, 0x6d].map((s) => parseCc([0xe1, 0x10, s, 0]).model), ['NTAG213', 'NTAG215', 'NTAG216'])
  assert.equal(parseCc([0xe1, 0x10, 0x12, 0x0f]).writable, false)
  assert.throws(() => parseCc([0x00, 0, 0, 0]), /NDEF/)
})

test('pecah halaman 4 byte dengan padding 0x00', () => {
  const p = splitPages(Buffer.from([1, 2, 3, 4, 5]))
  assert.equal(p.length, 2)
  assert.deepEqual([...p[1]], [5, 0, 0, 0])
})

test('kapasitas: URL yang tidak muat di NTAG213 ditolak', () => {
  const cc = parseCc([0xe1, 0x10, 0x12, 0])
  assert.doesNotThrow(() => assertFits(144, cc))
  assert.throws(() => assertFits(145, cc), /terlalu panjang/)
})

test('rencana kunci: CC dulu, dynamic lock sesuai model, static lock terakhir', () => {
  assert.deepEqual(lockPlan(parseCc([0xe1, 0x10, 0x12, 0])).map((s) => s.page), [3, 0x28, 2])
  assert.deepEqual(lockPlan(parseCc([0xe1, 0x10, 0x3e, 0])).map((s) => s.page), [3, 0x82, 2])
  assert.deepEqual(lockPlan(parseCc([0xe1, 0x10, 0x6d, 0])).map((s) => s.page), [3, 0xe2, 2])
  assert.throws(() => lockPlan(parseCc([0xe1, 0x10, 0x20, 0])), /tidak dikenali/)
})

test('tulis → verifikasi di NTAG215 tiruan, tanpa mengunci', async () => {
  const tag = fakeNtag()
  const status = []
  const res = await writeUrlToTag(tag.transmit, { url: URL_OK, onStatus: (t) => status.push(t) })
  assert.deepEqual(res, { uid: '04A1B2C3D4E5F6', model: 'NTAG215', locked: false })
  assert.equal(parseUrlFromTlv(tag.mem.subarray(16)), URL_OK)
  assert.equal(tag.mem[15], 0x00)
  assert.ok(!tag.log.some((a) => a.startsWith('ffd60003') || a.startsWith('ffd60002')), 'tidak menyentuh CC/lock')
  assert.deepEqual(status, ['Menulis… tahan kartu', 'Memverifikasi… tahan kartu'])
  // tulis ulang (kartu belum dikunci) tetap bisa
  await writeUrlToTag(tag.transmit, { url: URL_OK.replace('abcdef', 'zzzzzz') })
  assert.equal(parseUrlFromTlv(tag.mem.subarray(16)), URL_OK.replace('abcdef', 'zzzzzz'))
})

test('lock: true mengunci permanen dan tulis berikutnya ditolak', async () => {
  const tag = fakeNtag({ size: 0x12 })
  const res = await writeUrlToTag(tag.transmit, { url: URL_OK, lock: true })
  assert.equal(res.locked, true)
  assert.equal(tag.mem[15], 0x0f)
  assert.deepEqual([...tag.mem.subarray(0x28 * 4, 0x28 * 4 + 3)], [0xff, 0xff, 0xff])
  assert.deepEqual([...tag.mem.subarray(10, 12)], [0xff, 0xff])
  await assert.rejects(writeUrlToTag(tag.transmit, { url: URL_OK }), /sudah dikunci/)
})

test('kartu terkunci, bukan NDEF, URL kepanjangan, atau model tak dikenal (kunci) ditolak sebelum menulis', async () => {
  for (const [opts, url, re] of [
    [{ locked: true }, URL_OK, /sudah dikunci/],
    [{ ccMagic: 0x00 }, URL_OK, /NDEF/],
    [{ size: 0x12 }, 'https://contoh.id/c/Ab3dE5gH9k?' + 'x'.repeat(200), /terlalu panjang/],
    [{ size: 0x20 }, URL_OK, /tidak dikenali/],
  ]) {
    const tag = fakeNtag(opts)
    await assert.rejects(writeUrlToTag(tag.transmit, { url, lock: opts.size === 0x20 }), re)
    assert.ok(!tag.log.some((a) => a.startsWith('ffd6')), 'tidak ada penulisan')
  }
})

test('verifikasi gagal bila isi chip berbeda setelah ditulis, dan tidak dikunci', async () => {
  const tag = fakeNtag()
  const tx = async (apdu) => {
    const r = await tag.transmit(apdu)
    if (apdu[1] === 0xd6) tag.mem[20] ^= 0x01 // chip "salah tulis"
    return r
  }
  await assert.rejects(writeUrlToTag(tx, { url: URL_OK, lock: true }), /tidak cocok/)
  assert.equal(tag.mem[15], 0x00)
})

test('TLV yang ditulis sama persis dengan encodeUrlTlv', async () => {
  const tag = fakeNtag()
  await writeUrlToTag(tag.transmit, { url: URL_OK })
  const tlv = encodeUrlTlv(URL_OK)
  assert.deepEqual(tag.mem.subarray(16, 16 + tlv.length), tlv)
})
