import assert from 'node:assert/strict'
import { test } from 'node:test'
import { nfcErrorMessage } from '../nfc.js'

test('IO error (kartu terlepas) dijelaskan dalam bahasa Indonesia', () => {
  const byName = nfcErrorMessage({ name: 'NetworkError', message: 'Failed to write due to an IO error: null' })
  const byText = nfcErrorMessage(new Error('Failed to write due to an IO error: null'))
  for (const msg of [byName, byText]) {
    assert.match(msg, /Kartu terlepas/)
    assert.doesNotMatch(msg, /IO error/)
  }
})

test('galat izin, dukungan, dan tak ada kartu punya pesan sendiri', () => {
  assert.match(nfcErrorMessage({ name: 'NotAllowedError' }), /Izin NFC/)
  assert.match(nfcErrorMessage({ name: 'NotSupportedError' }), /NTAG/)
  assert.match(nfcErrorMessage({ name: 'NotReadableError' }), /tidak terbaca/)
  assert.match(nfcErrorMessage({ name: 'AbortError' }), /Tidak ada kartu/)
})

test('galat lain memakai pesan aslinya, atau teks bawaan bila kosong', () => {
  assert.equal(nfcErrorMessage(new Error('URL di chip tidak cocok.')), 'URL di chip tidak cocok.')
  assert.equal(nfcErrorMessage(undefined), 'Gagal memproses kartu.')
})
