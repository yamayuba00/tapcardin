import { test } from 'node:test'
import assert from 'node:assert/strict'
import { curveBox, curvePath, fitTextBox, layerBox, rawBox, setTextMode, textAnchor, textSize, textWidth, wrapText } from '../design.js'
import { FONT_LIST, fontMeta, fontUrl, nearestWeight } from '../fonts.js'

const box = { type: 'text', box: true, text: 'Kantor pusat Jalan Merdeka Nomor 12 Jakarta Pusat Indonesia', x: 10, y: 10, w: 40, h: 12, size: 14, lineHeight: 1.2, font: 'IBM Plex Sans', align: 'left' }

test('registri font: slug, tebal terdekat, font tak dikenal pakai Plex Sans', () => {
  assert.ok(FONT_LIST.length >= 28)
  assert.equal(new Set(FONT_LIST.map((f) => f.slug)).size, FONT_LIST.length)
  assert.equal(nearestWeight('Montserrat', 650), 600)
  assert.equal(fontUrl('Montserrat', 900), '/fonts/montserrat-800.woff2')
  assert.equal(fontUrl('Tidak Ada'), null)
  assert.equal(fontMeta('Tidak Ada').family, 'IBM Plex Sans')
})

test('lebar teks memakai faktor font: Bebas Neue lebih sempit dari Montserrat', () => {
  const t = 'NAMA LENGKAP'
  assert.ok(textWidth({ font: 'Bebas Neue' }, t, 3) < textWidth({ font: 'Montserrat' }, t, 3))
  assert.equal(textWidth({ font: 'IBM Plex Sans' }, 'abcd', 2), 4 * 2 * 0.55)
})

test('wrapText membungkus per kata dan menjaga baris baru', () => {
  const lines = wrapText({ font: 'IBM Plex Sans' }, 'satu dua tiga empat\nlima', 10, 3)
  assert.ok(lines.length >= 3)
  assert.equal(lines.at(-1), 'lima')
  lines.slice(0, -1).forEach((l) => assert.ok(textWidth({ font: 'IBM Plex Sans' }, l, 3) <= 10.01 || !l.includes(' ')))
})

test('fitTextBox memperkecil sampai muat, berhenti di 6 pt bila tetap tidak muat', () => {
  const r = fitTextBox(box)
  assert.ok(r.size < 14 && r.size >= 6)
  assert.equal(r.overflow, false)
  assert.equal(textSize(box), r.size)
  const over = fitTextBox({ ...box, text: 'panjang sekali '.repeat(30), w: 20, h: 4, size: 10 })
  assert.equal(over.size, 6)
  assert.equal(over.overflow, true)
  assert.deepEqual(rawBox(box), { x: 10, y: 10, w: 40, h: 12 })
})

test('textAnchor kotak teks mengikuti perataan horizontal & vertikal', () => {
  const top = textAnchor({ ...box, valign: 'top' })
  const mid = textAnchor({ ...box, valign: 'middle' })
  const bot = textAnchor({ ...box, valign: 'bottom' })
  assert.ok(top.y < mid.y && mid.y < bot.y)
  assert.equal(textAnchor({ ...box, align: 'center' }).x, 30)
  assert.equal(textAnchor({ ...box, align: 'right' }).x, 50)
})

test('teks melengkung: kotak simetris, arah atas di atas jangkar, bawah di bawah', () => {
  const up = { type: 'text', text: 'SEGEL RESMI', x: 40, y: 20, size: 9, font: 'IBM Plex Sans', curve: 'up', curveR: 15 }
  const b = curveBox(up)
  assert.ok(Math.abs(b.x + b.w / 2 - 40) < 0.01)
  assert.ok(b.y < 20)
  const down = curveBox({ ...up, curve: 'down' })
  assert.ok(down.y + down.h > 20)
  assert.match(curvePath(up), /^M40 50A15 15 0 1 1 40 20A15 15 0 1 1 40 50$/)
  assert.match(curvePath({ ...up, curve: 'down' }), /0 1 0 /)
  // diputar 90°: teks pindah ke kanan pusat lingkaran
  const rot = curveBox({ ...up, curveAngle: 90 })
  assert.ok(rot.x > 40)
  assert.deepEqual(layerBox(up), b)
})

test('setTextMode menjaga posisi visual saat berpindah mode', () => {
  const line = { id: 'a', type: 'text', text: 'Halo dunia', x: 20, y: 30, size: 10, font: 'IBM Plex Sans', align: 'left' }
  const asBox = setTextMode(line, 'box')
  assert.equal(asBox.box, true)
  assert.ok(Math.abs(asBox.x - rawBox(line).x) < 0.01)
  const back = setTextMode(asBox, 'line')
  assert.equal(back.box, undefined)
  assert.ok(Math.abs(back.y - textAnchor(asBox).y) < 0.01)
  const curved = setTextMode({ ...line, text: 'a\nb' }, 'curve')
  assert.equal(curved.text, 'a b')
  assert.equal(curved.curve, 'up')
  assert.equal(curved.align, 'center')
})
