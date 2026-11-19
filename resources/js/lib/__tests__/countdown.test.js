import { test } from 'node:test'
import assert from 'node:assert/strict'
import { clock, payState, secondsLeft } from '../countdown.js'

test('secondsLeft: memakai jam server, mengoreksi jam perangkat yang meleset', () => {
  const fetchedAt = Date.parse('2026-11-19T10:00:00Z')
  // jam perangkat 5 menit terlambat dibanding server
  const serverNow = '2026-11-19T10:05:00Z'
  assert.equal(secondsLeft('2026-11-19T10:35:00Z', serverNow, fetchedAt, fetchedAt), 1800)
  assert.equal(secondsLeft('2026-11-19T10:35:00Z', serverNow, fetchedAt, fetchedAt + 60_000), 1740)
  assert.equal(secondsLeft('2026-11-19T10:00:00Z', serverNow, fetchedAt, fetchedAt), 0, 'tidak pernah negatif')
  assert.equal(secondsLeft(null, serverNow, fetchedAt), null)
})

test('clock & payState', () => {
  assert.equal(clock(1805), '30:05')
  assert.equal(clock(3725), '1:02:05')
  assert.equal(clock(-3), '00:00')
  assert.equal(payState('paid', 0), 'paid')
  assert.equal(payState('issued', 120), 'pending')
  assert.equal(payState('issued', 0), 'expired')
  assert.equal(payState('void', null), 'void')
})
