// TRD §15 — logika JS diuji dengan runner bawaan Node (tanpa paket).
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { button, image, insertBlock, isHttps, link, prefixLines, progress, wrap } from '../markdownEdit.js'

test('wrap menebalkan seleksi atau menyisipkan placeholder terseleksi', () => {
  assert.deepEqual(wrap('halo dunia', 5, 10, '**'), { text: 'halo **dunia**', start: 7, end: 12 })
  const r = wrap('ab', 1, 1, '_')
  assert.equal(r.text, 'a_teks_b')
  assert.equal(r.text.slice(r.start, r.end), 'teks')
})

test('prefixLines memberi & melepas awalan judul / daftar', () => {
  assert.equal(prefixLines('satu\ndua', 0, 8, '- ').text, '- satu\n- dua')
  assert.equal(prefixLines('- satu\n- dua', 0, 12, '- ').text, 'satu\ndua')
  assert.equal(prefixLines('a\n- b', 2, 3, '## ').text, 'a\n## b') // awalan lain diganti
})

test('blok gambar & tombol berada di baris sendiri', () => {
  assert.equal(insertBlock('atas\nbawah', 4, 4, '---').text, 'atas\n\n---\n\nbawah')
  assert.equal(image('', 0, 0, ' https://cdn.x.id/a.png ', 'Ban[n]er').text, '![Banner](https://cdn.x.id/a.png)\n')
  assert.equal(button('Halo', 4, 4, 'Beli | sekarang', 'https://tapcard.id').text, 'Halo\n\n[[Tombol: Beli  sekarang | https://tapcard.id]]\n')
})

test('link memakai seleksi sebagai teks', () => {
  assert.equal(link('kunjungi situs', 9, 14, 'https://tapcard.id').text, 'kunjungi [situs](https://tapcard.id)')
  assert.equal(link('', 0, 0, 'https://a.id').text, '[tautan](https://a.id)')
})

test('isHttps hanya menerima URL https absolut', () => {
  assert.ok(isHttps('https://tapcard.id/promo?x=1'))
  assert.ok(!isHttps('http://tapcard.id'))
  assert.ok(!isHttps('javascript:alert(1)'))
  assert.ok(!isHttps('/relatif'))
  assert.ok(!isHttps('https://localhost'))
})

test('progress menghitung terkirim + gagal + dilewati', () => {
  assert.equal(progress({ total: 0 }), 0)
  assert.equal(progress({ total: 200, sent: 90, failed: 5, skipped: 5 }), 50)
  assert.equal(progress(null), 0)
})
