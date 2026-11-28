// Logika NTAG21x murni: kapasitas dari CC, pecah halaman, alur tulis → verifikasi → (opsional) kunci.
// Transport cukup fungsi `transmit(apdu: Buffer) => Promise<Buffer>` sehingga bisa diuji dengan tag tiruan.
import { checkResponse, getUidApdu, readPagesApdu, uidHex, updatePageApdu } from './apdu.js'
import { encodeUrlTlv, parseUrlFromTlv } from './ndef.js'

export const FIRST_DATA_PAGE = 4

/** Model NTAG21x menurut ukuran area data di CC byte 2 (×8 byte). dynLockPage = halaman dynamic lock bytes. */
export const NTAG_MODELS = {
  0x12: { model: 'NTAG213', bytes: 144, dynLockPage: 0x28 },
  0x3e: { model: 'NTAG215', bytes: 496, dynLockPage: 0x82 },
  0x6d: { model: 'NTAG216', bytes: 872, dynLockPage: 0xe2 },
}

export class TagError extends Error {
  constructor(code, message) {
    super(message)
    this.name = 'TagError'
    this.code = code
  }
}

/** Baca Capability Container (halaman 3). */
export function parseCc(cc) {
  const b = Buffer.from(cc)
  if (b.length < 4 || b[0] !== 0xe1) {
    throw new TagError('not_ndef', 'Kartu belum berformat NDEF (bukan NTAG21x / CC tidak valid).')
  }
  const known = NTAG_MODELS[b[2]]
  return {
    version: b[1],
    capacity: b[2] * 8,
    model: known?.model ?? null,
    dynLockPage: known?.dynLockPage ?? null,
    writable: (b[3] & 0x0f) === 0x00,
  }
}

/** Data → potongan 4 byte, sisa diisi 0x00. */
export function splitPages(data) {
  const buf = Buffer.from(data)
  const pages = []
  for (let i = 0; i < buf.length; i += 4) {
    const p = Buffer.alloc(4)
    buf.copy(p, 0, i, Math.min(i + 4, buf.length))
    pages.push(p)
  }
  return pages
}

/** Lempar bila TLV tidak muat di area data kartu. */
export function assertFits(tlvLength, cc) {
  if (tlvLength > cc.capacity) {
    throw new TagError('too_large', `URL terlalu panjang untuk kartu ini (${tlvLength} dari ${cc.capacity} byte).`)
  }
}

/** Halaman-halaman penguncian, urut aman: CC read-only → dynamic lock → static lock (terakhir, karena ikut mengunci CC). */
export function lockPlan(cc) {
  if (!cc.dynLockPage) throw new TagError('lock_unknown_model', 'Model chip tidak dikenali; penguncian dibatalkan demi keamanan.')
  return [
    { page: 3, bytes: [0x00, 0x00, 0x00, 0x0f] },
    { page: cc.dynLockPage, bytes: [0xff, 0xff, 0xff, 0x00] },
    { page: 2, bytes: [0x00, 0x00, 0xff, 0xff] },
  ]
}

const send = async (transmit, apdu, op) => checkResponse(await transmit(apdu), op)

export async function readUid(transmit) {
  const uid = await send(transmit, getUidApdu(), 'baca UID')
  if (uid.length < 4) throw new TagError('uid', 'UID chip tidak terbaca.')
  return uidHex(uid)
}

async function readRange(transmit, firstPage, byteLength) {
  const chunks = []
  for (let page = firstPage, got = 0; got < byteLength; page += 4, got += 16) {
    chunks.push(await send(transmit, readPagesApdu(page), 'baca'))
  }
  return Buffer.concat(chunks).subarray(0, byteLength)
}

/**
 * Tulis URL sebagai record NDEF URI, baca ulang & cocokkan, lalu kunci permanen bila `lock` true.
 * @param {(apdu: Buffer) => Promise<Buffer>} transmit
 * @param {{ url: string, lock?: boolean, onStatus?: (text: string) => void }} opts
 * @returns {Promise<{ uid: string, model: string|null, locked: boolean }>}
 */
export async function writeUrlToTag(transmit, { url, lock = false, onStatus = () => {} }) {
  const uid = await readUid(transmit)
  const head = await readRange(transmit, 0, 16) // halaman 0–3: UID, static lock (hal. 2), CC (hal. 3)
  const cc = parseCc(head.subarray(12, 16))
  if (!cc.writable || head[10] !== 0 || head[11] !== 0) {
    throw new TagError('locked', 'Kartu sudah dikunci permanen dan tidak bisa ditulis ulang.')
  }
  if (lock && !cc.dynLockPage) lockPlan(cc) // gagal sebelum menulis apa pun
  const tlv = encodeUrlTlv(url)
  assertFits(tlv.length, cc)

  onStatus('Menulis… tahan kartu')
  const pages = splitPages(tlv)
  for (let i = 0; i < pages.length; i++) {
    await send(transmit, updatePageApdu(FIRST_DATA_PAGE + i, pages[i]), 'tulis')
  }

  onStatus('Memverifikasi… tahan kartu')
  const back = await readRange(transmit, FIRST_DATA_PAGE, pages.length * 4)
  if (parseUrlFromTlv(back) !== url) {
    throw new TagError('verify', 'URL di chip tidak cocok. Ulangi tulis sebelum dikunci.')
  }
  if (lock) {
    onStatus('Mengunci chip…')
    for (const { page, bytes } of lockPlan(cc)) await send(transmit, updatePageApdu(page, bytes), 'kunci')
  }
  return { uid, model: cc.model, locked: lock }
}
