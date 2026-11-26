import { test } from 'node:test'
import assert from 'node:assert/strict'
import { easeOutCubic, tiltFromPointer, tweenValue } from '../motion.js'

test('easeOutCubic memetakan 0→0 dan 1→1, dijepit di luar rentang', () => {
  assert.equal(easeOutCubic(0), 0)
  assert.equal(easeOutCubic(1), 1)
  assert.equal(easeOutCubic(-3), 0)
  assert.equal(easeOutCubic(9), 1)
  assert.ok(easeOutCubic(0.5) > 0.5, 'di awal lebih cepat dari linear')
})

test('tweenValue menghitung angka bulat di antara from dan to', () => {
  assert.equal(tweenValue(10, 20, 0), 10)
  assert.equal(tweenValue(10, 20, 1), 20)
  const mid = tweenValue(10, 20, 0.5)
  assert.ok(mid > 15 && mid < 20)
  assert.equal(Number.isInteger(mid), true)
})

test('tiltFromPointer: tengah → 0, pojok → ±max', () => {
  assert.deepEqual(tiltFromPointer(50, 50, 100, 100), { rx: 0, ry: 0 })
  assert.deepEqual(tiltFromPointer(100, 0, 100, 100, 6), { rx: 6, ry: 6 })
  assert.deepEqual(tiltFromPointer(0, 100, 100, 100, 6), { rx: -6, ry: -6 })
})

test('tiltFromPointer: di luar kotak dijepit, ukuran nol aman', () => {
  assert.deepEqual(tiltFromPointer(500, 50, 100, 100, 4), { rx: 0, ry: 4 })
  assert.deepEqual(tiltFromPointer(10, 10, 0, 0), { rx: 0, ry: 0 })
})
