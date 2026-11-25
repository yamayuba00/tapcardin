import { test } from 'node:test'
import assert from 'node:assert/strict'
import { fitMotif, motifLayers, MOTIFS } from '../motifs.js'

const palette = [{ name: 'onyx-950', hex: 'p1' }, { name: 'champagne-500', hex: 'p2' }]
const box = { x: 20, y: 10, w: 60, h: 40 }
const EPS = 1e-6

function bounds(l) {
  if (l.type === 'line') return [Math.min(l.x1, l.x2), Math.min(l.y1, l.y2), Math.max(l.x1, l.x2), Math.max(l.y1, l.y2)]
  if (l.type === 'circle') return [l.cx - l.r, l.cy - l.r, l.cx + l.r, l.cy + l.r]
  return [l.x, l.y, l.x + l.w, l.y + l.h]
}

test('fitMotif keeps aspect ratio and centres inside a wide box', () => {
  const [out] = fitMotif([{ type: 'rect', x: 0, y: 0, w: 100, h: 50 }], { x: 0, y: 0, w: 200, h: 100 })
  assert.equal(out.w / out.h, 2)
  assert.ok(Math.abs(out.x - 50) < EPS)
  assert.ok(Math.abs(out.y) < EPS)
  assert.ok(Math.abs(out.w - 100) < EPS)
})

test('fitMotif scales stroke widths with the same factor', () => {
  const [out] = fitMotif([{ type: 'line', x1: 0, y1: 0, x2: 100, y2: 100, width: 2 }], { x: 0, y: 0, w: 50, h: 50 })
  assert.ok(Math.abs(out.width - 1) < EPS)
})

test('every built-in motif stays inside its fit box', () => {
  for (const m of MOTIFS) {
    const layers = motifLayers(m.key, box, palette)
    assert.ok(layers.length > 0, m.key)
    for (const l of layers) {
      const [x1, y1, x2, y2] = bounds(l)
      assert.ok(x1 >= box.x - EPS && y1 >= box.y - EPS, `${m.key} ${l.type} lewat kiri/atas`)
      assert.ok(x2 <= box.x + box.w + EPS && y2 <= box.y + box.h + EPS, `${m.key} ${l.type} lewat kanan/bawah`)
    }
  }
})

test('motif layers take palette colours by name', () => {
  const layers = motifLayers('diagonal', box, palette)
  assert.ok(layers.every((l) => ['p1', 'p2'].includes(l.color)))
})
