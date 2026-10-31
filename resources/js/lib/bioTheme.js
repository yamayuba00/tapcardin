// Tema bio link: server mengirim nama token palet (bukan hex) per variabel CSS semantik
// (lihat App\Support\Bio\BioTheme). Di sini hanya dipetakan ke var(--color-*) dari tokens.css.

export const BIO_DEFAULTS = { preset: 'onyx', header: 'center', avatar: 'circle', corners: 'rounded', button: 'solid', links: 'list', font: 'Syne', decoration: 'rings' }

/** Objek style berisi variabel CSS untuk satu subtree (aman CSP: diterapkan via CSSOM). */
export function themeStyle(theme) {
  const out = {}
  for (const [k, token] of Object.entries(theme?.vars ?? {})) out[`--${k}`] = `var(--color-${token})`
  return out
}

/**
 * Resolve pilihan tema di klien untuk preview langsung, memakai katalog dari GET /api/bio/themes
 * (warna aksen per preset sudah dihitung kontrasnya di server).
 */
export function resolveTheme(catalog, choice) {
  const t = { ...BIO_DEFAULTS, ...choice }
  const preset = catalog.presets.find((p) => p.key === t.preset) ?? catalog.presets[0]
  const accent = t.accent && preset.accent_vars[t.accent]
  return { ...t, preset: preset.key, mode: preset.mode, vars: { ...preset.vars, ...(accent ?? {}) } }
}

/** Pilihan saja (tanpa warna turunan) — yang dikirim ke PATCH /api/profiles/{id}. */
export function themeChoice(theme) {
  const out = {}
  for (const k of [...Object.keys(BIO_DEFAULTS), 'accent']) if (theme?.[k] !== undefined) out[k] = theme[k]
  return out
}

export const CORNER_CLASS = { sharp: 'rounded-none', rounded: 'rounded-md', pill: 'rounded-full' }
export const AVATAR_CLASS = { circle: 'rounded-full', rounded: 'rounded-lg', square: 'rounded-none' }
export const FONT_CLASS = { Syne: 'font-display', 'IBM Plex Sans': 'font-sans', 'IBM Plex Mono': 'font-mono' }
