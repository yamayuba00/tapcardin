import { test } from 'node:test'
import assert from 'node:assert/strict'
import { shareInflight } from '../inflight.js'

test('shareInflight: panggilan bersamaan berbagi satu permintaan, panggilan berikutnya baru', async () => {
  let calls = 0
  const load = shareInflight(async () => { calls++; await new Promise((r) => setTimeout(r, 10)); return calls })
  const [a, b, c] = await Promise.all([load(), load(), load()])
  assert.deepEqual([a, b, c, calls], [1, 1, 1, 1])
  assert.equal(await load(), 2)
})

test('shareInflight: galat dibagikan lalu bisa dicoba lagi', async () => {
  let n = 0
  const load = shareInflight(async () => { if (++n === 1) throw new Error('gagal'); return 'ok' })
  await assert.rejects(Promise.all([load(), load()]))
  assert.equal(await load(), 'ok')
})
