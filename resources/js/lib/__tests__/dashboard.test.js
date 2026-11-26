// Helper beranda — diuji dengan runner bawaan Node.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { chartPoints, deltaPill, lastDays, nearestIndex, shortDay } from '../dashboard.js'

test('lastDays mengambil n titik terakhir', () => {
  const s = [1, 2, 3, 4, 5].map((value) => ({ value }))
  assert.deepEqual(lastDays(s, 2).map((p) => p.value), [4, 5])
  assert.equal(lastDays(s, 30).length, 5)
  assert.equal(lastDays([], 7).length, 0)
})

test('chartPoints: nilai maksimum di puncak, nol di dasar, x merata', () => {
  const pts = chartPoints([0, 5, 10], { width: 100, height: 100, pad: 10 })
  assert.equal(pts[0].y, 90)
  assert.equal(pts[2].y, 10)
  assert.deepEqual(pts.map((p) => p.x), [0, 50, 100])
  assert.equal(chartPoints([7], { width: 100 })[0].x, 50)
  assert.equal(chartPoints([0, 0], { height: 100, pad: 10 })[0].y, 90)
})

test('nearestIndex memilih titik terdekat, -1 bila kosong', () => {
  const pts = chartPoints([1, 2, 3, 4], { width: 300 })
  assert.equal(nearestIndex(pts, 0), 0)
  assert.equal(nearestIndex(pts, 160), 2)
  assert.equal(nearestIndex(pts, 9999), 3)
  assert.equal(nearestIndex([], 10), -1)
})

test('deltaPill: naik, turun, dan null saat pembanding 0', () => {
  assert.deepEqual(deltaPill(150, 100), { value: 50, up: true })
  assert.deepEqual(deltaPill(50, 100), { value: 50, up: false })
  assert.equal(deltaPill(10, 0), null)
})

test('shortDay memakai tanggal kalender tanpa geser zona', () => {
  assert.match(shortDay('2026-10-24'), /24/)
  assert.equal(shortDay(null), '')
})
