// TRD §15 — logika JS diuji dengan runner bawaan Node (tanpa paket).
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { delta, initials, relative, toE164 } from '../format.js'

test('toE164 sama dengan App\\Support\\Phone', () => {
  assert.equal(toE164('0812-3456-7890'), '+6281234567890')
  assert.equal(toE164('+62 812 3456 7890'), '+6281234567890')
  assert.equal(toE164('81234567890'), '+6281234567890')
  assert.equal(toE164('0065 9123 4567'), '+6591234567')
  assert.equal(toE164('123'), null)
  assert.equal(toE164(''), null)
})

test('relative time dalam bahasa Indonesia', () => {
  const now = new Date('2026-10-29T12:00:00Z')
  assert.equal(relative('2026-10-29T11:59:30Z', now), 'baru saja')
  assert.equal(relative('2026-10-29T11:55:00Z', now), '5 menit lalu')
  assert.equal(relative('2026-10-29T09:00:00Z', now), '3 jam lalu')
  assert.equal(relative('2026-10-28T10:00:00Z', now), 'kemarin')
  assert.equal(relative('2026-10-25T12:00:00Z', now), '4 hari lalu')
  assert.equal(relative(null, now), '—')
})

test('delta & initials', () => {
  assert.equal(delta(15, 10), 50)
  assert.equal(delta(5, 10), -50)
  assert.equal(delta(5, 0), null)
  assert.equal(initials('Rina Saputra Dewi'), 'RS')
  assert.equal(initials(''), '')
})
