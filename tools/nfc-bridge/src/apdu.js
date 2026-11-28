// Pembangun APDU pseudo PC/SC (ACS ACR122U / ACR1252U, dan reader PC/SC v2 lain) untuk NTAG21x.

/** Baca UID kartu: FF CA 00 00 00. */
export const getUidApdu = () => Buffer.from([0xff, 0xca, 0x00, 0x00, 0x00])

/** Read Binary 16 byte (4 halaman) mulai `page`: FF B0 00 <page> 10. */
export function readPagesApdu(page) {
  assertPage(page)
  return Buffer.from([0xff, 0xb0, 0x00, page, 0x10])
}

/** Update Binary satu halaman (4 byte): FF D6 00 <page> 04 <b0 b1 b2 b3>. */
export function updatePageApdu(page, bytes) {
  assertPage(page)
  const data = Buffer.from(bytes)
  if (data.length !== 4) throw new Error('Satu halaman NTAG harus 4 byte')
  return Buffer.concat([Buffer.from([0xff, 0xd6, 0x00, page, 0x04]), data])
}

function assertPage(page) {
  if (!Number.isInteger(page) || page < 0 || page > 0xff) throw new Error(`Halaman tidak valid: ${page}`)
}

export class ApduError extends Error {
  constructor(sw, op) {
    super(`Reader menolak perintah ${op} (SW ${sw})`)
    this.name = 'ApduError'
    this.sw = sw
  }
}

/** Pisahkan data & status word; lempar ApduError bila SW bukan 90 00. */
export function checkResponse(res, op = 'APDU') {
  const buf = Buffer.from(res ?? [])
  if (buf.length < 2) throw new ApduError('—', op)
  const sw = buf.subarray(-2).toString('hex').toUpperCase()
  if (sw !== '9000') throw new ApduError(sw, op)
  return buf.subarray(0, -2)
}

/** UID biner → hex huruf besar tanpa pemisah (server menormalkan format apa pun). */
export const uidHex = (buf) => Buffer.from(buf).toString('hex').toUpperCase()
