// Tiruan NTAG21x di balik reader PC/SC: menjawab FF CA / FF B0 / FF D6 seperti ACR122U.
const OK = Buffer.from([0x90, 0x00])
const FAIL = Buffer.from([0x63, 0x00])

export function fakeNtag({ size = 0x3e, uid = [0x04, 0xa1, 0xb2, 0xc3, 0xd4, 0xe5, 0xf6], locked = false, ccMagic = 0xe1 } = {}) {
  const pagesTotal = { 0x12: 45, 0x3e: 135, 0x6d: 231 }[size] ?? 64
  const mem = Buffer.alloc(pagesTotal * 4)
  Buffer.from(uid.slice(0, 3)).copy(mem, 0)
  Buffer.from(uid.slice(3, 7)).copy(mem, 4)
  mem.set([ccMagic, 0x10, size, locked ? 0x0f : 0x00], 12)
  mem.set([0x03, 0x00, 0xfe], 16) // TLV NDEF kosong bawaan pabrik
  const log = []
  // seperti chip asli: CC read-only hanya penanda NDEF; yang benar-benar mengunci ialah lock bit (OR, permanen)
  const dynLockPage = { 0x12: 0x28, 0x3e: 0x82, 0x6d: 0xe2 }[size]
  const ro = () => mem[10] !== 0 || (locked && (mem[15] & 0x0f) !== 0)
  const transmit = async (apdu) => {
    const a = Buffer.from(apdu)
    log.push(a.toString('hex'))
    if (a[0] === 0xff && a[1] === 0xca) return Buffer.concat([Buffer.from(uid), OK])
    if (a[0] === 0xff && a[1] === 0xb0) {
      const out = Buffer.alloc(16)
      for (let i = 0; i < 16; i++) out[i] = mem[(a[3] * 4 + i) % mem.length] // NTAG membungkus ke halaman 0
      return Buffer.concat([out, OK])
    }
    if (a[0] === 0xff && a[1] === 0xd6 && a[4] === 4) {
      const page = a[3]
      const data = a.subarray(5, 9)
      if (page >= pagesTotal) return FAIL
      if (page === 2) { mem[10] |= data[2]; mem[11] |= data[3]; return OK } // byte 0–1 tak berubah, lock bit OR
      if (page === 3) { if (mem[10]) return FAIL; for (let i = 0; i < 4; i++) mem[12 + i] |= data[i]; return OK } // CC = OTP
      if (page === dynLockPage) { for (let i = 0; i < 4; i++) mem[page * 4 + i] |= data[i]; return OK }
      if (page >= 4 && ro()) return FAIL
      data.copy(mem, page * 4)
      return OK
    }
    return Buffer.from([0x6a, 0x81])
  }
  return { transmit, mem, log }
}
