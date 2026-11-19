import { test } from 'node:test'
import assert from 'node:assert/strict'
import { alignRef, alignTo, distributeIn, setGuide, stepRepeat } from '../layout.js'
import { layerBox, snapMove } from '../design.js'

const size = { w: 85.6, h: 54 }
const r = (id, x, y, w = 10, h = 5) => ({ id, type: 'rect', x, y, w, h, fill: '#000000' })

test('alignRef: seleksi (≥2), kanvas, area aman; satu elemen selalu terhadap kanvas', () => {
  const ls = [r('a', 10, 10), r('b', 30, 20)]
  assert.deepEqual(alignRef(ls, 'selection', size, 4), { x: 10, y: 10, w: 30, h: 15 })
  assert.deepEqual(alignRef([ls[0]], 'selection', size, 4), { x: 0, y: 0, w: 85.6, h: 54 })
  assert.deepEqual(alignRef(ls, 'canvas', size, 4), { x: 0, y: 0, w: 85.6, h: 54 })
  assert.deepEqual(alignRef(ls, 'safe', size, 4), { x: 4, y: 4, w: 77.6, h: 46 })
})

test('alignTo: rata kanan & tengah vertikal terhadap area aman', () => {
  const ref = alignRef([], 'safe', size, 4)
  const [a] = alignTo([r('a', 10, 10)], 'right', ref)
  assert.equal(a.x + a.w, 81.6)
  const [b] = alignTo([r('b', 10, 10)], 'vmiddle', ref)
  assert.equal(b.y + b.h / 2, 27)
})

test('distributeIn: dua elemen menempel tepi kanvas, jarak sama untuk tiga elemen', () => {
  const two = distributeIn([r('a', 20, 10), r('b', 40, 10)], 'h', { x: 0, y: 0, w: 85.6, h: 54 })
  assert.equal(two[0].x, 0)
  assert.equal(two[1].x + two[1].w, 85.6)
  const three = distributeIn([r('a', 5, 10), r('b', 12, 10), r('c', 60, 10)], 'h', { x: 0, y: 0, w: 60, h: 54 })
  const boxes = three.map((l) => layerBox(l))
  const gap1 = boxes[1].x - (boxes[0].x + boxes[0].w)
  const gap2 = boxes[2].x - (boxes[1].x + boxes[1].w)
  assert.ok(Math.abs(gap1 - gap2) < 0.01)
})

test('stepRepeat: 2 × 3 = 5 salinan bergrup dengan jarak sesuai; dibatasi maksimum layer', () => {
  const src = [r('a', 5, 5, 10, 4)]
  const { copies, group, truncated } = stepRepeat(src, { rows: 2, cols: 3, gapX: 2, gapY: 1 }, src)
  assert.equal(copies.length, 5)
  assert.equal(truncated, false)
  assert.ok(copies.every((c) => c.group === group))
  assert.ok(copies.some((c) => c.x === 29 && c.y === 5)) // kolom ke-3: 5 + 2 × (10 + 2)
  assert.ok(copies.some((c) => c.x === 5 && c.y === 10)) // baris ke-2: 5 + (4 + 1)
  assert.equal(new Set(copies.map((c) => c.id)).size, 5)
  const big = stepRepeat(src, { rows: 10, cols: 10 }, src, {}, 20)
  assert.equal(big.truncated, true)
  assert.ok(big.copies.length <= 20)
})

test('snapMove menempel ke garis bantu pengguna dan menampilkannya', () => {
  const box = { x: 20, y: 20, w: 10, h: 5 }
  const s = snapMove(box, 4.7, 0, { size, threshold: 0.5, guides: { x: [35], y: [] } })
  assert.equal(s.dx, 5) // tepi kanan 30 + 5 = 35
  assert.deepEqual(s.guides.x, [35])
  assert.equal(snapMove(box, 4.7, 0, { size, threshold: 0.5 }).dx, 4.7)
})

test('setGuide: tambah, pindah, hapus, unik & terurut', () => {
  assert.deepEqual(setGuide([], null, 10.04), [10])
  assert.deepEqual(setGuide([10, 20], 10, 30), [20, 30])
  assert.deepEqual(setGuide([10, 20], 20, null), [10])
  assert.deepEqual(setGuide([10], null, 10), [10])
})
