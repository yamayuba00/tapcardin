import { test } from 'node:test'
import assert from 'node:assert/strict'
import { blockPayload, editableData, embedOf, newBlock, newField, parseVideo, presentBlock } from '../bioBlocks.js'

test('parseVideo mengenali penyedia yang sama dengan server', () => {
  const cases = {
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10': ['youtube', 'dQw4w9WgXcQ', 'video'],
    'https://m.youtube.com/shorts/dQw4w9WgXcQ': ['youtube', 'dQw4w9WgXcQ', 'short'],
    'https://youtu.be/dQw4w9WgXcQ?si=x': ['youtube', 'dQw4w9WgXcQ', 'video'],
    'https://vimeo.com/76979871': ['vimeo', '76979871', 'video'],
    'https://www.tiktok.com/@rina/video/7234567890123456789': ['tiktok', '7234567890123456789', 'video'],
    'https://open.spotify.com/intl-id/track/4uLU6hMCjMI75M1A2tKUQC': ['spotify', '4uLU6hMCjMI75M1A2tKUQC', 'track'],
  }
  for (const [url, [provider, id, kind]] of Object.entries(cases)) assert.deepEqual(parseVideo(url), { provider, id, kind }, url)
  for (const bad of ['https://youtube.com/watch?v=short', 'javascript:alert(1)', 'https://evil.com/youtube.com/watch?v=dQw4w9WgXcQ', 'ftp://vimeo.com/76979871', '', null]) {
    assert.equal(parseVideo(bad), null, String(bad))
  }
})

test('embedOf memberi ukuran per penyedia', () => {
  assert.equal(embedOf({ provider: 'youtube', id: 'x', kind: 'short' }).ratio, '9/16')
  assert.equal(embedOf({ provider: 'spotify', id: 'x', kind: 'playlist' }).height, 352)
  assert.equal(embedOf({ provider: 'spotify', id: 'x', kind: 'track' }).height, 152)
})

test('presentBlock menyembunyikan blok yang belum lengkap', () => {
  assert.equal(presentBlock(newBlock('button')), null)
  assert.equal(presentBlock(newBlock('video')), null)
  const b = newBlock('button')
  b.data.label = ' Ajak Kolaborasi '
  assert.equal(presentBlock(b).label, 'Ajak Kolaborasi')
  const v = newBlock('video')
  v.data.url = 'https://youtu.be/dQw4w9WgXcQ'
  assert.equal(presentBlock(v).embed_url, 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
})

test('blok simpan kontak memakai teks bawaan bila kosong', () => {
  const b = newBlock('save_contact')
  assert.equal(presentBlock(b).label, 'Simpan ke Kontak HP')
  b.data.label = '  '
  assert.equal(presentBlock(b).label, 'Simpan ke Kontak HP')
  b.data.label = 'Simpan nomor saya'
  assert.equal(presentBlock(b).label, 'Simpan nomor saya')
})

test('newBlock tidak berbagi objek data antar blok', () => {
  const a = newBlock('button')
  const b = newBlock('button')
  a.data.label = 'x'
  assert.equal(b.data.label, '')
  assert.notEqual(a.key, b.key)
})

test('blockPayload membuang kunci lokal', () => {
  const t = { ...newBlock('text'), id: '01ABC' }
  assert.deepEqual(blockPayload(t), { id: '01ABC', type: 'text', is_visible: true, data: { title: '', body: '', align: 'left' } })
  assert.equal(blockPayload(newBlock('text')).id, undefined)
})

test('formulir: pratinjau memakai judul bawaan, pertanyaan kosong & pilihan kosong dibuang', () => {
  const f = newBlock('form')
  assert.equal(presentBlock(f), null, 'tanpa teks tombol belum tampil')
  f.data.label = 'Ajak Kolaborasi'
  const q = newField('select')
  q.label = 'Jenis'
  q.options = ['Endorse', ' ', 'Review']
  f.data.fields = [q, newField('text')]
  const p = presentBlock(f)
  assert.equal(p.title, 'Ajak Kolaborasi')
  assert.equal(p.success, 'Terima kasih! Pesan Anda sudah terkirim.')
  assert.deepEqual(p.fields.map((x) => x.label), ['Jenis'])
  assert.deepEqual(p.fields[0].options, ['Endorse', 'Review'])
  const payload = blockPayload(f).data.fields
  assert.deepEqual(payload[0].options, ['Endorse', 'Review'])
  assert.equal(payload[1].options, undefined)
  assert.equal(payload[0].id, undefined, 'ID baru diberi server')
})

test('editableData melengkapi pertanyaan formulir dari server', () => {
  const d = editableData('form', { label: 'X', fields: [{ id: 'abc123', type: 'text', label: 'Nama brand', required: true }] })
  assert.deepEqual(d.fields[0], { key: 'abc123', placeholder: '', id: 'abc123', type: 'text', label: 'Nama brand', required: true, options: [] })
  assert.equal(d.success, '')
  const payload = blockPayload({ id: 'B1', type: 'form', is_visible: true, data: d }).data.fields[0]
  assert.equal(payload.id, 'abc123', 'ID lama dipertahankan agar jawaban tetap terpetakan')
})
