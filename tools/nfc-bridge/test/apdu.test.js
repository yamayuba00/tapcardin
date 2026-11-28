import assert from 'node:assert/strict'
import { test } from 'node:test'
import { ApduError, checkResponse, getUidApdu, readPagesApdu, uidHex, updatePageApdu } from '../src/apdu.js'

test('APDU UID, baca & tulis halaman sesuai format pseudo-APDU ACS', () => {
  assert.equal(getUidApdu().toString('hex'), 'ffca000000')
  assert.equal(readPagesApdu(4).toString('hex'), 'ffb0000410')
  assert.equal(updatePageApdu(5, [1, 2, 3, 4]).toString('hex'), 'ffd600050401020304')
})

test('halaman & panjang data divalidasi', () => {
  assert.throws(() => updatePageApdu(4, [1, 2, 3]), /4 byte/)
  assert.throws(() => readPagesApdu(256), /tidak valid/)
  assert.throws(() => readPagesApdu(-1), /tidak valid/)
})

test('status word 90 00 dilepas; selain itu ApduError', () => {
  assert.deepEqual([...checkResponse(Buffer.from([0xaa, 0x90, 0x00]))], [0xaa])
  assert.throws(() => checkResponse(Buffer.from([0x63, 0x00]), 'tulis'), (e) => e instanceof ApduError && e.sw === '6300')
  assert.throws(() => checkResponse(Buffer.from([])), ApduError)
  assert.equal(uidHex(Buffer.from([0x04, 0xa1, 0x0b])), '04A10B')
})
