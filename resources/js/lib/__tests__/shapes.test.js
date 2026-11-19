import { test } from 'node:test'
import assert from 'node:assert/strict'
import { imageRect, maskPoints, strokeDash } from '../design.js'

test('strokeDash: putus memakai panjang (bawaan 3× tebal), titik bulat, dash lama tetap putus', () => {
  assert.deepEqual(strokeDash({}, 0.5), {})
  assert.deepEqual(strokeDash({ strokeStyle: 'dashed' }, 0.5), { dasharray: '1.5 0.9' })
  assert.deepEqual(strokeDash({ strokeStyle: 'dashed', dashLen: 2 }, 0.5), { dasharray: '2 1.2' })
  assert.deepEqual(strokeDash({ strokeStyle: 'dotted' }, 0.5), { dasharray: '0.01 1', linecap: 'round' })
  assert.deepEqual(strokeDash({ dash: true }, 0.3), { dasharray: '0.9 0.54' })
})

test('maskPoints: bintang 10 titik, poligon sesuai sisi, segi enam 6, lingkaran/kotak null', () => {
  const box = { x: 0, y: 0, w: 10, h: 10 }
  assert.equal(maskPoints({ ...box, mask: 'star' }).split(' ').length, 10)
  assert.equal(maskPoints({ ...box, mask: 'polygon', maskSides: 8 }).split(' ').length, 8)
  assert.equal(maskPoints({ ...box, mask: 'hexagon' }).split(' ').length, 6)
  assert.equal(maskPoints({ ...box, mask: 'circle' }), null)
  assert.equal(maskPoints({ ...box }), null)
})

test('imageRect: tanpa zoom = kotak; zoom 2 di tengah; geser ke tepi menampilkan sisi itu', () => {
  const l = { x: 10, y: 20, w: 10, h: 10 }
  assert.deepEqual(imageRect(l), { x: 10, y: 20, w: 10, h: 10 })
  assert.deepEqual(imageRect({ ...l, cropZoom: 2 }), { x: 5, y: 15, w: 20, h: 20 })
  // cropX -1: tepi kiri gambar sejajar tepi kiri kotak; cropX 1: tepi kanan sejajar
  assert.equal(imageRect({ ...l, cropZoom: 2, cropX: -1 }).x, 10)
  const r = imageRect({ ...l, cropZoom: 2, cropX: 1 })
  assert.equal(r.x + r.w, 20)
})
