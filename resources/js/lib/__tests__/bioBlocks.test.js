import { test } from 'node:test'
import assert from 'node:assert/strict'
import { blockPayload, embedOf, newBlock, parseVideo, presentBlock } from '../bioBlocks.js'

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
  assert.equal(presentBlock(newBlock('gallery')), null)
  const b = newBlock('button')
  b.data.label = ' Ajak Kolaborasi '
  assert.equal(presentBlock(b).label, 'Ajak Kolaborasi')
  const v = newBlock('video')
  v.data.url = 'https://youtu.be/dQw4w9WgXcQ'
  assert.equal(presentBlock(v).embed_url, 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
})

test('newBlock tidak berbagi objek data antar blok', () => {
  const a = newBlock('gallery')
  const b = newBlock('gallery')
  a.data.images.push({ path: 'x' })
  assert.equal(b.data.images.length, 0)
  assert.notEqual(a.key, b.key)
})

test('blockPayload membuang URL gambar & kunci lokal', () => {
  const g = { ...newBlock('gallery'), id: '01ABC' }
  g.data.images = [{ path: 't1/bio/a.webp', url: 'http://x/a.webp', caption: null }]
  assert.deepEqual(blockPayload(g), { id: '01ABC', type: 'gallery', is_visible: true, data: { layout: 'grid', images: [{ path: 't1/bio/a.webp', caption: '' }] } })
  assert.equal(blockPayload(newBlock('text')).id, undefined)
})
