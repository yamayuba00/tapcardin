// Aktivitas beranda: tap berulang dari kartu & perangkat yang sama dalam 1 jam digabung jadi satu baris (hitungannya dibawa).
// Urutan masukan: terbaru di depan. Baris non-tap tidak digabung.
const HOUR = 3600000

export function groupRecent(rows = []) {
  const out = []
  for (const r of rows) {
    const key = `${r.type}|${r.title}|${(r.meta ?? []).join('|')}`
    const last = out.at(-1)
    if (r.type === 'tap' && last && last.key === key && Date.parse(last.at) - Date.parse(r.at) <= HOUR) {
      last.count++
      continue
    }
    out.push({ ...r, key, count: 1 })
  }
  return out
}
