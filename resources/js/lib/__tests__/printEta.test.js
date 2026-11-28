import { test } from 'node:test'
import assert from 'node:assert/strict'
import { formatPrintDate, formatPrintRange } from '../printEta.js'

test('formatPrintDate: mengubah YYYY-MM-DD menjadi tanggal Indonesia', () => {
  assert.equal(formatPrintDate('2026-12-04'), '4 Des 2026')
  assert.equal(formatPrintDate('2027-01-15'), '15 Jan 2027')
})

test('formatPrintDate: menolak nilai kosong atau tidak valid', () => {
  assert.equal(formatPrintDate(null), null)
  assert.equal(formatPrintDate(''), null)
  assert.equal(formatPrintDate('04/12/2026'), null)
  assert.equal(formatPrintDate('2026-13-01'), null)
})

test('formatPrintRange: menampilkan rentang min – maks', () => {
  assert.equal(formatPrintRange('2026-12-04', '2026-12-10'), '4 Des 2026 – 10 Des 2026')
})

test('formatPrintRange: satu tanggal bila min dan maks sama', () => {
  assert.equal(formatPrintRange('2026-12-04', '2026-12-04'), '4 Des 2026')
})

test('formatPrintRange: null bila salah satu tanggal belum ada', () => {
  assert.equal(formatPrintRange(null, '2026-12-10'), null)
  assert.equal(formatPrintRange('2026-12-04', undefined), null)
})
