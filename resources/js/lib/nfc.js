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

const MAX_TRIES = 3
const TAP_TIMEOUT_MS = 20000

/** AbortController yang batal sendiri bila kartu tidak ditempel dalam waktu wajar. */
function timed(ms = TAP_TIMEOUT_MS) {
  const ctrl = new AbortController()
  const t = setTimeout(() => ctrl.abort(), ms)
  return { ctrl, done: () => clearTimeout(t) }
}

/**
 * Encode satu kartu: tulis URL → baca ulang & cocokkan → (opsional) kunci permanen. "IO error" (kartu bergeser di
 * tengah penulisan) diulang otomatis sampai 3 kali; galat lain langsung dilempar (pakai nfcErrorMessage).
 * Dipakai Stasiun encode & Jual kartu (kartu polos ditulis di tempat).
 * @param {string} url
 * @param {{ lockAfter?: boolean, onStatus?: (text: string) => void, onRetry?: () => void, register?: (ctrl: AbortController) => void }} [opts]
 * @returns {Promise<string>} nomor seri (UID) chip
 */
export async function encodeUrl(url, { lockAfter = true, onStatus = () => {}, onRetry = () => {}, register = () => {} } = {}) {
  for (let attempt = 1; ; attempt++) {
    const { ctrl, done } = timed()
    register(ctrl)
    try {
      onStatus(attempt === 1 ? 'Tempel kartu dan tahan…' : `Tempel ulang dan tahan (percobaan ${attempt}/${MAX_TRIES})…`)
      await writeUrl(url, { signal: ctrl.signal })
      break
    } catch (e) {
      if (e?.name !== 'NetworkError' || attempt === MAX_TRIES) throw e
      onRetry()
    } finally {
      done()
    }
  }
  onStatus('Memverifikasi… tahan kartu')
  const { ctrl, done } = timed()
  register(ctrl)
  let read
  try {
    read = await readOnce({ signal: ctrl.signal })
  } finally {
    done()
  }
  if (read.url !== url) throw new Error('URL di chip tidak cocok. Ulangi tulis sebelum dikunci.')
  if (lockAfter) await lock()
  ctrl.abort()
  return read.serialNumber
}
