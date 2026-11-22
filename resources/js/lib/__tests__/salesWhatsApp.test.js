import { test } from 'node:test'
import assert from 'node:assert/strict'
import { salesWhatsAppUrl } from '../salesWhatsApp.js'

test('nomor kosong → tidak ada tautan (tombol disembunyikan)', () => {
  assert.equal(salesWhatsAppUrl('', 'hero'), null)
  assert.equal(salesWhatsAppUrl(null, 'hero'), null)
})

test('nomor dibersihkan & pesan menyebut asal klik serta paket', () => {
  const url = new URL(salesWhatsAppUrl('+62 812-3456-7890', 'pricing', 'Growth'))
  assert.equal(url.origin + url.pathname, 'https://wa.me/6281234567890')
  const text = url.searchParams.get('text')
  assert.match(text, /TapCard/)
  assert.match(text, /paket Growth/)
  assert.match(text, /tabel harga/)
})
