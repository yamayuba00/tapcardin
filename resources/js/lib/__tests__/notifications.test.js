import { test } from 'node:test'
import assert from 'node:assert/strict'
import { group, link, message } from '../notifications.js'

test('link: tujuan per jenis notifikasi', () => {
  assert.equal(link('tap', { card: 'ABCDEFGHIJ' }), '/cards/ABCDEFGHIJ')
  assert.equal(link('lead_new', { id: '01ABC' }), '/connections/01ABC')
  assert.equal(link('reminder_due', {}), '/follow-up')
  assert.equal(link('invoice_paid', { id: 'INV1' }), '/billing/invoices/INV1')
  assert.equal(link('order_status', { id: 'ORD1' }), '/orders/ORD1')
  assert.equal(link('subscription_reminder', {}), '/billing')
  assert.equal(link('webhook_disabled', {}), '/webhooks')
  assert.equal(link('announcement', {}), null)
})

test('message & group: teks manusiawi dan kelompok filter', () => {
  assert.equal(message('tap', { city: 'Bandung' }), 'Seseorang membuka profil Anda · Bandung')
  assert.equal(message('tidak_dikenal', {}), null)
  assert.equal(group('lost_card_tapped'), 'tap')
  assert.equal(group('lead_assigned'), 'lead')
  assert.equal(group('trial_ending'), 'billing')
  assert.equal(group('order_unpaid'), 'order')
  assert.equal(group('announcement'), 'system')
})
