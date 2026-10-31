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

export async function writeUrl(url) {
  // eslint-disable-next-line no-undef
  const w = new NDEFReader()
  await w.write({ records: [{ recordType: 'url', data: url }] }, { overwrite: true })
}

export async function lock() {
  // eslint-disable-next-line no-undef
  await new NDEFReader().makeReadOnly() // permanen, hanya dipanggil setelah verifikasi
}
