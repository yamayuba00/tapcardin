// Penggabungan kiriman notifikasi realtime ke state lonceng (tanpa dependensi, bisa diuji langsung dengan node --test).

// id notifikasi → hitungan terakhir yang sudah ditampilkan (Reverb & SSE bisa sempat aktif bersamaan; notifikasi tap
// gabungan disiarkan ulang dengan id sama dan `count` lebih besar → diperbarui di tempat, bukan ditambah)
const seen = new Map()

/**
 * Terapkan satu kiriman ke state: notifikasi baru → di depan daftar + lencana naik; notifikasi gabungan yang sama
 * (id dikenal, count bertambah) → diganti di tempat tanpa menaikkan lencana. @returns {boolean|null} true = pembaruan,
 * false = baru, null = kiriman ganda yang diabaikan.
 */
export function applyIncoming(state, type, data, id) {
  const count = Number(data?.count ?? 1)
  const item = id ? state.items.find((x) => x.id === id) : null
  // dikenal dari kiriman realtime sebelumnya, atau sudah ada di daftar hasil API
  const known = id ? (seen.get(id) ?? (item ? Number(item.data?.count ?? 1) : undefined)) : undefined
  if (known !== undefined) {
    if (count <= known) return null
    seen.set(id, count)
    if (item) {
      item.data = data
      if (data?.at) item.at = data.at
      state.items = [item, ...state.items.filter((x) => x !== item)]
    }
    return true
  }
  if (id) {
    seen.set(id, count)
    if (seen.size > 500) seen.delete(seen.keys().next().value)
  }
  state.unread++
  if (state.loaded && id && !state.items.some((x) => x.id === id)) state.items.unshift({ id, type, data, read: false, at: new Date().toISOString() })
  return false
}

/** Khusus uji: kosongkan memori id yang sudah tampil. */
export const resetSeen = () => seen.clear()
