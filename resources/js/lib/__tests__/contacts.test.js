import { test } from 'node:test'
import assert from 'node:assert/strict'
import { displayOf, handleOf, hrefOf, previewLinks } from '../contacts.js'

const ig = { key: 'instagram', input: 'handle', action: 'link', url_template: 'https://instagram.com/{v}', label: 'Instagram', icon: 'Instagram', group: 'social' }
const li = { ...ig, key: 'linkedin', url_template: 'https://www.linkedin.com/in/{v}' }
const wa = { key: 'whatsapp', input: 'phone', action: 'link', url_template: 'https://wa.me/{digits}', label: 'WhatsApp', icon: 'MessageCircle', group: 'contact' }
const bank = { key: 'bank', input: 'bank', action: 'copy', url_template: null, label: 'Rekening bank', icon: 'Landmark', group: 'payment' }
const addr = { key: 'address', input: 'text', action: 'map', url_template: 'https://maps.google.com/?q={v}', label: 'Alamat', icon: 'MapPin', group: 'business' }

test('handle dari @username, username, atau tautan profil', () => {
  assert.equal(handleOf(ig, '@rina.saputra'), 'rina.saputra')
  assert.equal(handleOf(ig, 'https://instagram.com/rina/?igsh=x'), 'rina')
  assert.equal(handleOf(li, 'https://www.linkedin.com/in/rina-s/'), 'rina-s')
})

test('href per jenis: template, digit, salin tanpa tautan, alamat ter-encode', () => {
  assert.equal(hrefOf(ig, '@rina'), 'https://instagram.com/rina')
  assert.equal(hrefOf(wa, '+6281234567890'), 'https://wa.me/6281234567890')
  assert.equal(hrefOf(bank, '123'), null)
  assert.equal(hrefOf(addr, 'Jl. A 1'), 'https://maps.google.com/?q=Jl.%20A%201')
})

test('tampilan & preview: bank dengan pemilik, field kosong/tersembunyi dilewati', () => {
  assert.equal(displayOf(bank, '1234 567', { bank: 'BCA', holder: 'Rina' }), 'BCA · 1234567 a.n. Rina')
  const links = previewLinks([
    { type: 'instagram', value: 'rina', is_visible: true },
    { type: 'bank', value: '123456', meta: { bank: 'BCA', holder: 'R' }, is_visible: true },
    { type: 'instagram', value: '', is_visible: true },
    { type: 'instagram', value: 'x', is_visible: false },
  ], { instagram: ig, bank })
  assert.equal(links.length, 2)
  assert.equal(links[1].copy, '123456')
  assert.equal(links[1].url, null)
})
