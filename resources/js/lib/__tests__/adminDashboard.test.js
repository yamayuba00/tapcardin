import { test } from 'node:test'
import assert from 'node:assert/strict'
import { attentionItems, periodDelta, periodSums } from '../adminDashboard.js'

const series = (vals) => vals.map((value, i) => ({ date: `2026-01-${String(i + 1).padStart(2, '0')}`, value }))

test('periodSums: 7 hari terakhir vs 7 hari sebelumnya dari deret 30 hari', () => {
  const vals = [...Array(16).fill(1), ...Array(7).fill(2), ...Array(7).fill(3)]
  assert.deepEqual(periodSums(series(vals), 7), { current: 21, previous: 14 })
})

test('periodSums: deret pendek → pembanding null', () => {
  assert.deepEqual(periodSums(series([1, 2, 3]), 7), { current: 6, previous: null })
})

test('periodDelta: naik dan turun dengan persen absolut', () => {
  const up = periodDelta(series([...Array(7).fill(1), ...Array(7).fill(2)]), 7)
  assert.deepEqual(up, { value: 100, up: true })
  const down = periodDelta(series([...Array(7).fill(4), ...Array(7).fill(2)]), 7)
  assert.deepEqual(down, { value: 50, up: false })
})

test('periodDelta: pembanding 0 atau deret pendek → null (tidak ada pil)', () => {
  assert.equal(periodDelta(series([...Array(7).fill(0), ...Array(7).fill(5)]), 7), null)
  assert.equal(periodDelta(series([5, 5]), 7), null)
})

test('attentionItems: hitungan null dan 0 disembunyikan, urut prioritas', () => {
  const m = {
    attention: { payment_reviews: null, approvals: 0, refunds_decide: 2, refunds_payout: null, orders_review: 1 },
    risk: { invoices_failed: 3, subscriptions_ending: 0, domains_problem: 0 },
    replacements_pending: 0,
  }
  assert.deepEqual(attentionItems(m).map((x) => x.key), ['refunds_decide', 'orders_review', 'invoices_failed'])
})

test('attentionItems: data kosong → daftar kosong', () => {
  assert.deepEqual(attentionItems(null), [])
  assert.deepEqual(attentionItems({}), [])
})
