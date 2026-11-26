import { test } from 'node:test'
import assert from 'node:assert/strict'
import { FOLLOWUP_WA_TEMPLATE, followupWhatsAppUrl } from '../followupWhatsApp.js'

test('nomor +62 dan 08… dinormalisasi ke 62… tanpa spasi atau tanda', () => {
  assert.equal(followupWhatsAppUrl('+62 812-3456-7890'), 'https://wa.me/6281234567890')
  assert.equal(followupWhatsAppUrl('0812 3456 7890'), 'https://wa.me/6281234567890')
  assert.equal(followupWhatsAppUrl('0062 812 3456 7890'), 'https://wa.me/6281234567890')
  assert.equal(followupWhatsAppUrl('+6281234567890'), 'https://wa.me/6281234567890')
})

test('bukan seluler Indonesia atau tidak valid → null (tombol disembunyikan)', () => {
  assert.equal(followupWhatsAppUrl(null), null)
  assert.equal(followupWhatsAppUrl(''), null)
  assert.equal(followupWhatsAppUrl('12345'), null)
  assert.equal(followupWhatsAppUrl('+1 555 123 4567'), null)
  assert.equal(followupWhatsAppUrl('021 1234567'), null) // telepon rumah Jakarta
  assert.equal(followupWhatsAppUrl('+62 812 abc'), null)
})

test('template kosong → tanpa parameter text', () => {
  assert.equal(FOLLOWUP_WA_TEMPLATE, '')
  assert.equal(followupWhatsAppUrl('0812 3456 7890'), 'https://wa.me/6281234567890')
  assert.equal(followupWhatsAppUrl('0812 3456 7890', ''), 'https://wa.me/6281234567890')
})

test('template terisi → parameter text ter-encode', () => {
  assert.equal(
    followupWhatsAppUrl('0812 3456 7890', 'Halo & selamat siang'),
    'https://wa.me/6281234567890?text=Halo%20%26%20selamat%20siang',
  )
})
