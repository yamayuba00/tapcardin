import { test, beforeEach } from 'node:test'
import assert from 'node:assert/strict'
import { applyIncoming, resetSeen } from '../notificationMerge.js'
import { message } from '../notifications.js'

const state = () => ({ unread: 0, loaded: true, items: [] })
beforeEach(() => resetSeen())

test('tap gabungan: id sama diperbarui di tempat, lencana tetap satu', () => {
  const s = state()
  assert.equal(applyIncoming(s, 'tap', { card: 'A', count: 1, at: '2026-11-19T10:00:00Z' }, 7), false)
  applyIncoming(s, 'lead_new', { name: 'Budi' }, 8)
  assert.equal(applyIncoming(s, 'tap', { card: 'A', count: 2, at: '2026-11-19T10:05:00Z' }, 7), true)
  assert.equal(s.unread, 2)
  assert.equal(s.items.length, 2)
  assert.equal(s.items[0].id, 7, 'yang diperbarui naik ke atas')
  assert.equal(s.items[0].data.count, 2)
  assert.equal(s.items[0].at, '2026-11-19T10:05:00Z')
})

test('kiriman ganda (Reverb + SSE) diabaikan', () => {
  const s = state()
  applyIncoming(s, 'tap', { count: 1 }, 1)
  assert.equal(applyIncoming(s, 'tap', { count: 1 }, 1), null)
  applyIncoming(s, 'tap', { count: 3 }, 1)
  assert.equal(applyIncoming(s, 'tap', { count: 3 }, 1), null)
  assert.equal(s.unread, 1)
})

test('notifikasi dari API lalu diperbarui realtime tidak dihitung dua kali', () => {
  const s = { unread: 1, loaded: true, items: [{ id: 5, type: 'tap', data: { count: 2 }, read: false, at: 'x' }] }
  assert.equal(applyIncoming(s, 'tap', { count: 2 }, 5), null)
  assert.equal(applyIncoming(s, 'tap', { count: 4 }, 5), true)
  assert.equal(s.unread, 1)
  assert.equal(s.items.length, 1)
})

test('teks tap: satu vs gabungan', () => {
  assert.equal(message('tap', { city: 'Bandung' }), 'Seseorang membuka profil Anda · Bandung')
  assert.equal(message('tap', { count: 1, city: 'Bandung' }), 'Seseorang membuka profil Anda · Bandung')
  assert.equal(message('tap', { count: 5, label: 'Kerja' }), '5 orang membuka profil Anda (kartu Kerja)')
  assert.equal(message('tap', { count: 2 }), '2 orang membuka profil Anda')
  assert.equal(message('tap', { count: 3, label: 'Kartu digital' }), '3 orang membuka profil Anda (Kartu digital)')
})
