import { test } from 'node:test'
import assert from 'node:assert/strict'
import { autoFitWidth, cardSize, createLayer, fillVars, isSystem, layerBox, moveLayer, ptToMm, resizeLayer, snapMove, textSize } from '../design.js'
import { createHistory } from '../history.js'

test('fillVars mengganti variabel profil dan membiarkan yang tidak dikenal', () => {
  assert.equal(fillVars('{{name}} · {{ title }}', { name: 'Rina', title: 'CEO' }), 'Rina · CEO')
  assert.equal(fillVars('{{company}}', { company: '' }), '{{company}}')
  assert.equal(fillVars('{{unknown}}', {}), '{{unknown}}')
})

test('moveLayer menggeser semua bentuk koordinat', () => {
  assert.deepEqual(moveLayer({ type: 'text', x: 1, y: 2 }, 0.5, -1), { type: 'text', x: 1.5, y: 1 })
  assert.deepEqual(moveLayer({ type: 'line', x1: 0, y1: 0, x2: 10, y2: 0 }, 1, 1), { type: 'line', x1: 1, y1: 1, x2: 11, y2: 1 })
  assert.deepEqual(moveLayer({ type: 'rings', cx: 5, cy: 5 }, -2, 2), { type: 'rings', cx: 3, cy: 7 })
})

test('createLayer di tengah kartu sesuai orientasi', () => {
  const t = createLayer('text', cardSize('portrait'), 'X')
  assert.equal(t.x, 27)
  assert.equal(t.y, 42.8)
  assert.equal(createLayer('rect', cardSize('landscape'), 'X').x, 32.8)
})

test('layerBox teks rata tengah dan pt → mm', () => {
  assert.equal(Math.round(ptToMm(10) * 1000), 3528)
  const box = layerBox({ type: 'text', text: 'ABCD', x: 50, y: 20, size: 10, font: 'Syne', align: 'center' })
  assert.ok(Math.abs(box.x + box.w / 2 - 50) < 1e-9)
})

test('isSystem mengenali elemen terkunci', () => {
  assert.equal(isSystem({ id: 'sys-qr' }), true)
  assert.equal(isSystem({ id: 'l1', locked: true }), true)
  assert.equal(isSystem({ id: 'l1' }), false)
})

test('history undo/redo dan abaikan keadaan identik', () => {
  const h = createHistory({ n: 0 })
  assert.equal(h.push({ n: 0 }), false)
  h.push({ n: 1 })
  h.push({ n: 2 })
  assert.deepEqual(h.undo(), { n: 1 })
  assert.deepEqual(h.undo(), { n: 0 })
  assert.equal(h.canUndo, false)
  assert.deepEqual(h.redo(), { n: 1 })
  h.push({ n: 5 })
  assert.equal(h.canRedo, false, 'perubahan baru menghapus redo')
})

test('textSize memperkecil nama panjang sampai muat fitWidth, tidak di bawah 6 pt', () => {
  const l = { type: 'text', text: '{{name}}', size: 16, x: 6, y: 40, align: 'left', fitWidth: 40 }
  assert.equal(textSize(l, { name: 'Rina' }), 16, 'nama pendek tidak berubah')
  const long = textSize(l, { name: 'Anastasia Kusumawardhani' })
  assert.ok(long < 16 && long >= 6)
  assert.ok(layerBox(l, { name: 'Anastasia Kusumawardhani' }).w <= 40.5)
  assert.equal(textSize(l, { name: 'X'.repeat(120) }), 6, 'batas bawah 6 pt')
  assert.equal(textSize({ ...l, fitWidth: undefined }, { name: 'X'.repeat(120) }), 16, 'tanpa fitWidth tidak diubah')
})

test('resizeLayer: sudut kotak, rasio gambar tetap, garis, lingkaran', () => {
  const rect = { type: 'rect', x: 10, y: 10, w: 20, h: 10 }
  assert.deepEqual(resizeLayer(rect, 'se', 5, 2), { ...rect, w: 25, h: 12 })
  assert.deepEqual(resizeLayer(rect, 'nw', 5, 2), { ...rect, x: 15, y: 12, w: 15, h: 8 })
  assert.equal(resizeLayer(rect, 'se', -50, -50).w, 1, 'tidak lebih kecil dari 1 mm')
  const img = { type: 'image', x: 0, y: 0, w: 20, h: 10 }
  const r = resizeLayer(img, 'se', 10, 0)
  assert.equal(r.w / r.h, 2, 'gambar menjaga rasio')
  assert.deepEqual(resizeLayer({ type: 'line', x1: 0, y1: 0, x2: 10, y2: 0 }, 'p2', 5, 1), { type: 'line', x1: 0, y1: 0, x2: 15, y2: 1 })
  assert.equal(resizeLayer({ type: 'circle', cx: 10, cy: 10, r: 5 }, 'se', 2, 1).r, 7)
  const t = resizeLayer({ type: 'text', text: 'Halo', size: 10, x: 10, y: 10, align: 'left' }, 'se', 100, 0)
  assert.equal(t.size, 72, 'teks dibatasi 72 pt')
})

test('snapMove menempel ke tengah kartu, safe zone, dan layer lain', () => {
  const size = { w: 85.6, h: 54 }
  const box = { x: 10, y: 10, w: 20, h: 10 } // tengah x = 20
  const c = snapMove(box, 22.5, 0, { size, threshold: 1 }) // tengah → 42.5, dekat 42.8
  assert.equal(round2(c.dx), 22.8)
  assert.deepEqual(c.guides.x, [42.8])
  const s = snapMove(box, -5.6, 0, { size, threshold: 1 }) // tepi kiri → 4.4, dekat safe 4
  assert.equal(round2(s.dx), -6)
  const o = snapMove(box, 0, 19.5, { size, others: [{ x: 50, y: 40, w: 10, h: 5 }], threshold: 1 }) // bawah 39.5 → 40
  assert.equal(round2(o.dy), 20)
  const free = snapMove(box, 7.3, 3.3, { size, threshold: 0.2 })
  assert.deepEqual([free.dx, free.dy, free.guides.x.length], [7.3, 3.3, 0])
})

const round2 = (n) => Math.round(n * 100) / 100

import { alignLayers, angleTo, distributeLayers, expandGroups, kitVars, polygonPoints, resolveColor, transplantLayers, unionBox } from '../design.js'

test('rotasi 90° menukar lebar & tinggi kotak; teks multi-baris lebih tinggi', () => {
  const b = layerBox({ type: 'rect', x: 0, y: 0, w: 20, h: 10, rotate: 90 })
  assert.equal(Math.round(b.w), 10)
  assert.equal(Math.round(b.h), 20)
  const one = layerBox({ type: 'text', text: 'Halo', x: 0, y: 10, size: 10 })
  const two = layerBox({ type: 'text', text: 'Halo\nDunia', x: 0, y: 10, size: 10, lineHeight: 1.5 })
  assert.ok(two.h > one.h * 2)
})

test('ratakan & distribusi', () => {
  const a = { id: 'a', type: 'rect', x: 0, y: 0, w: 10, h: 10 }
  const b = { id: 'b', type: 'rect', x: 30, y: 5, w: 10, h: 10 }
  const c = { id: 'c', type: 'rect', x: 50, y: 0, w: 10, h: 10 }
  assert.deepEqual(alignLayers([a, b], 'top', { w: 85.6, h: 54 }).map((l) => l.y), [0, 0])
  assert.equal(alignLayers([a], 'hcenter', { w: 85.6, h: 54 })[0].x, 37.8, 'satu layer: terhadap kanvas')
  const d = distributeLayers([a, b, c], 'h')
  assert.equal(d[1].x, 25, 'celah sama rata')
  assert.deepEqual(unionBox([a, c]), { x: 0, y: 0, w: 60, h: 10 })
})

test('grup, sudut, poligon, warna brand kit', () => {
  const layers = [{ id: 'a', group: 'g' }, { id: 'b', group: 'g' }, { id: 'c' }]
  assert.deepEqual(expandGroups(['a'], layers), ['a', 'b'])
  assert.equal(angleTo({ x: 0, y: 0 }, { x: 10, y: 0 }), 90)
  assert.equal(angleTo({ x: 0, y: 0 }, { x: 10, y: 1 }, true), 90, 'snap 15°')
  assert.equal(polygonPoints({ x: 0, y: 0, w: 10, h: 10, sides: 5, star: true }).split(' ').length, 10)
  const kit = { colors: { primary: '#2F6E58' }, name: 'Saputra', tagline: 'Pajak' }
  assert.equal(resolveColor('kit:primary', kit), '#2F6E58')
  assert.equal(resolveColor('kit:secondary', null, 'currentColor'), 'currentColor')
  assert.deepEqual(kitVars(kit), { brand: 'Saputra', tagline: 'Pajak' })
})

test('salin elemen kartu → lanyard: diskalakan muat lebar tali & di tengah', () => {
  const card = [{ id: 'logo', type: 'rect', x: 10, y: 10, w: 40, h: 40 }, { id: 't', type: 'text', text: 'Rina', x: 30, y: 60, size: 12 }]
  const out = transplantLayers(card, { w: 150, h: 20 }, { safe: 1.5 })
  const box = unionBox(out)
  assert.ok(box.h <= 17.01, 'tinggi muat di dalam margin tali')
  assert.ok(Math.abs(box.y + box.h / 2 - 10) < 0.6, 'di tengah melintang')
  assert.notEqual(out[0].id, 'logo', 'id baru')
  assert.ok(out[1].size >= 6, 'teks tidak di bawah 6 pt')
})

test('teks berisi data dibatasi zona aman sesuai perataan; teks tetap/bleed/berputar tidak diubah', () => {
  const card = { w: 85.6 }
  const name = (x, align = 'left', extra = {}) => ({ type: 'text', text: '{{name}}', x, y: 30, size: 16, align, ...extra })
  assert.equal(autoFitWidth(name(8), card), 85.6 - 4 - 8)
  assert.equal(autoFitWidth(name(85.6 / 2, 'center'), card), 85.6 - 8)
  assert.equal(autoFitWidth(name(80, 'right'), card), 76)
  assert.equal(autoFitWidth(name(8, 'left', { fitWidth: 30 }), card), 30, 'fitWidth eksplisit menang')
  assert.equal(autoFitWidth({ ...name(8), text: 'TapCard' }, card), null, 'teks tetap')
  assert.equal(autoFitWidth(name(-2, 'left', { allowBleed: true }), card), null)
  assert.equal(autoFitWidth(name(8, 'left', { rotate: 90 }), card), null)
  // nama sangat panjang mengecil (min 6 pt) bila diberi lebar otomatis
  const long = { ...name(8), fitWidth: autoFitWidth(name(8), card) }
  assert.ok(textSize(long, { name: 'Raden Ajeng Kusumawardhani Prameswari Wijayakusuma' }) < 16)
  assert.equal(textSize(long, { name: 'Rina' }), 16)
})
