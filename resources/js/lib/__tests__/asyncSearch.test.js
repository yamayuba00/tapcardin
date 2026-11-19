import { test, mock } from 'node:test'
import assert from 'node:assert/strict'
import { createAsyncSearch } from '../asyncSearch.js'

const tick = () => new Promise((r) => setImmediate(r))

test('debounce: hanya ketikan terakhir yang dikirim ke server', async () => {
  mock.timers.enable({ apis: ['setTimeout'] })
  const calls = []
  const results = []
  const s = createAsyncSearch(async (q) => { calls.push(q); return [q] }, { delay: 250, onResult: (v) => results.push(v) })
  s.run('a'); s.run('ab'); mock.timers.tick(200); s.run('abc')
  mock.timers.tick(249)
  assert.deepEqual(calls, [])
  mock.timers.tick(1)
  mock.timers.reset()
  await tick()
  assert.deepEqual(calls, ['abc'])
  assert.deepEqual(results, [['abc']])
})

test('jawaban lama yang datang belakangan diabaikan', async () => {
  const pending = {}
  const results = []
  const s = createAsyncSearch((q) => new Promise((r) => { pending[q] = r }), { onResult: (v) => results.push(v) })
  s.now('lam')
  s.now('lama')
  pending.lama(['baru'])
  pending.lam(['usang'])
  await tick()
  assert.deepEqual(results, [['baru']])
})

test('galat hanya dilaporkan untuk permintaan terbaru; cancel membuang jawaban', async () => {
  const errors = []
  const results = []
  let fail = true
  const s = createAsyncSearch(async () => { if (fail) throw new Error('x'); return ['ok'] }, { onResult: (v) => results.push(v), onError: (e) => errors.push(e.message) })
  await s.now('a')
  assert.deepEqual(errors, ['x'])
  fail = false
  const p = s.now('b')
  s.cancel()
  await p
  assert.deepEqual(results, [])
})
