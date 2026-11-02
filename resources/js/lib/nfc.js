// TRD §7.2 — Web NFC (Chrome Android, HTTPS + gesture pengguna).
export const nfcSupported = () => 'NDEFReader' in window

/** @returns {Promise<{serialNumber: string, url: string|null}>} */
export async function readOnce({ signal } = {}) {
  // eslint-disable-next-line no-undef
  const reader = new NDEFReader()
  await reader.scan({ signal })
  return new Promise((resolve, reject) => {
    reader.onreadingerror = () => reject(new Error('Kartu tidak terbaca, coba tempel lebih lama'))
    reader.onreading = ({ serialNumber, message }) => {
      const rec = message.records.find((r) => r.recordType === 'url')
      resolve({ serialNumber, url: rec ? new TextDecoder().decode(rec.data) : null })
    }
  })
}

/** Token kartu TapCard dari URL chip (`…/c/{token}`, boleh ber-query), atau null bila bukan kartu TapCard. */
export function cardTokenFromUrl(url) {
  const m = /\/c\/([0-9A-Za-z]{10})(?:[/?#]|$)/.exec(String(url ?? ''))
  return m ? m[1] : null
}

export async function writeUrl(url, { signal } = {}) {
  // eslint-disable-next-line no-undef
  const w = new NDEFReader()
  await w.write({ records: [{ recordType: 'url', data: url }] }, { overwrite: true, signal })
}

/**
 * Pesan galat Web NFC dalam bahasa Indonesia. "Failed to write due to an IO error" (NetworkError) berarti
 * koneksi ke chip putus di tengah penulisan: kartu bergeser / terlalu jauh dari antena, atau chip sudah dikunci permanen.
 */
export function nfcErrorMessage(e) {
  const name = e?.name
  const text = String(e?.message ?? '')
  if (name === 'NetworkError' || /IO error/i.test(text)) {
    return 'Kartu terlepas saat ditulis. Tempelkan kartu tepat di area NFC ponsel (biasanya dekat kamera belakang), jangan digeser sampai selesai. Kartu yang sudah dikunci permanen tidak bisa ditulis ulang.'
  }
  if (name === 'NotAllowedError') return 'Izin NFC ditolak. Aktifkan NFC di ponsel dan izinkan situs ini memakai NFC (Chrome → Setelan situs).'
  if (name === 'NotSupportedError') return 'NFC tidak tersedia atau kartu tidak didukung. Pastikan NFC menyala dan kartu bertipe NTAG.'
  if (name === 'NotReadableError') return 'Kartu tidak terbaca. Coba tempel lagi dengan posisi lebih diam.'
  if (name === 'AbortError') return 'Tidak ada kartu terdeteksi. Tekan Tulis lalu tempelkan kartu.'
  return text || 'Gagal memproses kartu.'
}

export async function lock() {
  // eslint-disable-next-line no-undef
  await new NDEFReader().makeReadOnly() // permanen, hanya dipanggil setelah verifikasi
}
