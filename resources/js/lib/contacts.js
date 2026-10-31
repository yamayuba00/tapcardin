// Logika murni kontak bio link di klien (preview langsung editor) — cerminan App\Support\Contacts\ContactValue.

const digits = (v) => String(v ?? '').replace(/\D/g, '')
const local = (e164) => (String(e164).startsWith('+62') ? `0${String(e164).slice(3)}` : String(e164))

/** Username dari isian bebas: "@rina", "rina", atau tautan profil yang ditempel. */
export function handleOf(type, value) {
  let v = String(value ?? '').trim()
  if (/^https?:\/\//i.test(v)) {
    try {
      v = new URL(v).pathname.replace(/^\/+|\/+$/g, '')
      if (type.key === 'linkedin') v = v.replace(/^in\//, '')
    } catch { return '' }
  }
  return v.replace(/\s+/g, '').replace(/^[@~]+/, '')
}

export function hrefOf(type, value) {
  if (['copy', 'none'].includes(type.action)) return null
  if (!type.url_template) return type.input === 'url' ? value : null
  const v = type.input === 'handle' ? handleOf(type, value) : type.input === 'text' ? encodeURIComponent(value) : value
  return type.url_template.replace('{v}', v).replace('{digits}', digits(value))
}

export function displayOf(type, value, meta = {}) {
  switch (type.input) {
    case 'handle': return `@${handleOf(type, value)}`
    case 'bank': return `${meta.bank ?? ''} · ${digits(value)}${meta.holder ? ` a.n. ${meta.holder}` : ''}`
    case 'url': return String(value).replace(/^https?:\/\/(www\.)?/, '').replace(/\/$/, '')
    case 'image': return type.label
    default: return value
  }
}

/**
 * Daftar tautan untuk PublicProfileView dari isian editor (belum disimpan).
 * @param {Array} fields isian editor
 * @param {Record<string, object>} types jenis kontak per key
 * @param {Set<string|number>} skip indeks field yang sudah tampil sebagai tombol cepat
 */
export function previewLinks(fields, types, skip = new Set()) {
  return fields
    .map((f, i) => ({ f, i, t: types[f.type] }))
    .filter(({ f, i, t }) => t && f.is_visible && String(f.value ?? '').trim() && !skip.has(i))
    .map(({ f, i, t }) => ({
      id: f.id ?? `n${i}`, type: t.key, group: t.group, icon: t.icon, action: t.action,
      label: f.label || t.label, display: displayOf(t, f.value, f.meta ?? {}),
      url: t.action === 'image' || !hrefOf(t, f.value) ? null : '#',
      copy: t.action === 'copy' ? (t.input === 'wallet' ? local(f.value) : digits(f.value) || f.value) : null,
      image: t.action === 'image' ? f.image_url ?? null : null,
    }))
}
