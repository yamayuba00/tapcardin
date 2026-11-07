import { test } from 'node:test'
import assert from 'node:assert/strict'
import { LAYOUTS, SAFE, buildLayout, isSimple, layoutsFor, titleLayers } from '../merchLayouts.js'

const SIZES = { tshirt: { w: 290, h: 400 }, jacket: { w: 280, h: 350 }, totebag: { w: 280, h: 320 }, mug: { w: 200, h: 85 }, cap: { w: 100, h: 55 } }
const c = { title: 'Kopi Senja', sub: 'Sejak 2020', ink: '#111111' }

/** kotak kasar lapisan (mm) untuk memastikan semuanya di dalam area cetak */
function box(l) {
  if (l.type === 'image' || l.type === 'rect') return { x: l.x, y: l.y, w: l.w, h: l.h }
  const hMm = l.size * 0.3528
  return { x: l.x - l.fitWidth / 2, y: l.y - hMm, w: l.fitWidth, h: hMm }
}

test('setiap layout tiap produk & sisi menghasilkan lapisan s-* di dalam area cetak', () => {
  for (const [sil, sides] of Object.entries(LAYOUTS)) {
    for (const [which, list] of Object.entries(sides)) {
      for (const { key } of list) {
        const size = SIZES[sil]
        const layers = buildLayout(key, size, c)
        assert.ok(layers.length, `${sil}/${which}/${key} kosong`)
        assert.ok(layers.every(isSimple), `${sil}/${which}/${key} id harus s-*`)
        assert.equal(new Set(layers.map((l) => l.id)).size, layers.length, `${sil}/${key} id ganda`)
        for (const l of layers) {
          const b = box(l)
          assert.ok(b.x >= -0.01 && b.y >= -0.01 && b.x + b.w <= size.w + 0.01 && b.y + b.h <= size.h + 0.01, `${sil}/${which}/${key}/${l.id} keluar area: ${JSON.stringify(b)}`)
          if (l.type === 'text') assert.ok(l.size >= 6 && l.size <= 120, `${sil}/${key} ukuran teks ${l.size}`)
        }
      }
    }
  }
})

test('logo memakai logo brand kit bila tidak ada unggahan, atau aset unggahan', () => {
  assert.equal(buildLayout('center', SIZES.tshirt, c).find((l) => l.id === 's-logo').asset, 'kit:logo')
  const up = buildLayout('center', SIZES.tshirt, { ...c, logo: { asset: 't1/designs/a.png', url: '/s/a.png', px_w: 800, px_h: 400 } }).find((l) => l.id === 's-logo')
  assert.deepEqual([up.asset, up.fit], ['t1/designs/a.png', 'contain'])
})

test('nama keliling mug mengulang judul; titleLayers menemukan semuanya', () => {
  const layers = buildLayout('mugwrap', SIZES.mug, c)
  assert.equal(titleLayers(layers).length, 3)
  assert.ok(titleLayers(layers).every((l) => l.text === 'Kopi Senja'))
  assert.equal(layoutsFor('payung', 'back')[0].key, 'back', 'siluet tak dikenal → kaos')
  assert.equal(layoutsFor('mug', 'back')[0].key, 'mugcenter', 'produk 1 sisi → layout depan')
  assert.ok(SAFE > 0)
})
