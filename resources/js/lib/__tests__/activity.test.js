import { test } from 'node:test'
import assert from 'node:assert/strict'
import { groupRecent } from '../activity.js'

const tap = (at, meta = ['Kartu hitam']) => ({ type: 'tap', at, title: 'Profil Anda dibuka', meta })

test('tap dari kartu sama dalam satu jam digabung', () => {
  const rows = [tap('2026-11-26T10:30:00Z'), tap('2026-11-26T10:10:00Z'), tap('2026-11-26T09:50:00Z')]
  const out = groupRecent(rows)
  assert.equal(out.length, 1)
  assert.equal(out[0].count, 3)
  assert.equal(out[0].at, '2026-11-26T10:30:00Z')
})

test('tap lebih dari satu jam terpisah', () => {
  const out = groupRecent([tap('2026-11-26T10:30:00Z'), tap('2026-11-26T08:00:00Z')])
  assert.equal(out.length, 2)
  assert.deepEqual(out.map((r) => r.count), [1, 1])
})

test('kartu berbeda tidak digabung', () => {
  const out = groupRecent([tap('2026-11-26T10:30:00Z', ['QR']), tap('2026-11-26T10:20:00Z', ['NFC'])])
  assert.equal(out.length, 2)
})

test('koneksi baru tidak pernah digabung', () => {
  const c = (at) => ({ type: 'connection', at, title: 'Budi terhubung', meta: [] })
  assert.equal(groupRecent([c('2026-11-26T10:30:00Z'), c('2026-11-26T10:29:00Z')]).length, 2)
})
