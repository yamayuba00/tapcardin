// Hitung mundur tagihan: waktu server = acuan (jam HP pengguna bisa meleset), dikoreksi selisih saat data diambil.

/**
 * Sisa detik sampai `expiresAt`. `serverNow` = jam server saat respons dibuat; `fetchedAt` = jam perangkat saat respons
 * diterima — selisihnya mengoreksi jam perangkat yang salah. Tidak pernah negatif; null bila tanpa batas waktu.
 */
export function secondsLeft(expiresAt, serverNow, fetchedAt, now = Date.now()) {
  if (!expiresAt) return null
  const skew = serverNow ? Date.parse(serverNow) - fetchedAt : 0
  return Math.max(0, Math.floor((Date.parse(expiresAt) - (now + skew)) / 1000))
}

/** 1805 → "30:05"; 3725 → "1:02:05". */
export function clock(seconds) {
  const s = Math.max(0, Math.floor(seconds ?? 0))
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const pad = (n) => String(n).padStart(2, '0')
  return h ? `${h}:${pad(m)}:${pad(s % 60)}` : `${pad(m)}:${pad(s % 60)}`
}

/**
 * Status tampilan tagihan: berhasil | kedaluwarsa | menunggu | batal.
 * Invoice `issued` yang batas waktunya lewat dianggap kedaluwarsa walau penjadwal belum membatalkannya.
 */
export function payState(status, left) {
  if (status === 'paid') return 'paid'
  if (status === 'void') return 'void'
  if (['issued', 'overdue'].includes(status)) return left === 0 ? 'expired' : 'pending'
  return 'void'
}
