import { test } from 'node:test'
import assert from 'node:assert/strict'
import { resolveTheme, themeChoice, themeStyle } from '../bioTheme.js'

const catalog = {
  presets: [
    { key: 'onyx', mode: 'dark', vars: { bg: 'onyx-950', text: 'bone-50', accent: 'champagne-500' }, accent_vars: { 'ink-950': { accent: 'bone-50', 'accent-ink': 'ink-950', 'accent-text': 'bone-50' } } },
    { key: 'ivory', mode: 'light', vars: { bg: 'bone-50', text: 'ink-950', accent: 'champagne-500' }, accent_vars: {} },
  ],
}

test('themeStyle memetakan nama token ke var(--color-*)', () => {
  assert.deepEqual(themeStyle({ vars: { bg: 'onyx-950', 'accent-ink': 'ink-950' } }), { '--bg': 'var(--color-onyx-950)', '--accent-ink': 'var(--color-ink-950)' })
  assert.deepEqual(themeStyle(null), {})
})

test('resolveTheme memakai aksen yang sudah dicek kontrasnya dan default opsi', () => {
  const t = resolveTheme(catalog, { preset: 'onyx', accent: 'ink-950', links: 'grid' })
  assert.equal(t.vars.accent, 'bone-50', 'aksen tak terlihat diganti server')
  assert.equal(t.vars.bg, 'onyx-950')
  assert.equal(t.links, 'grid')
  assert.equal(t.header, 'center')
  assert.equal(resolveTheme(catalog, { preset: 'tidak-ada' }).preset, 'onyx')
  assert.equal(resolveTheme(catalog, { preset: 'ivory' }).mode, 'light')
})

test('themeChoice membuang warna turunan', () => {
  assert.deepEqual(themeChoice({ preset: 'ivory', mode: 'light', vars: {}, accent: 'sky-700', links: 'buttons' }), { preset: 'ivory', links: 'buttons', accent: 'sky-700' })
})
