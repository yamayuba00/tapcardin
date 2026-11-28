// Encoding NDEF murni (tanpa perangkat keras): record URI (NFC Forum RTD-URI) dibungkus TLV Type 2 Tag.

/** Kode prefiks URI NFC Forum (RTD-URI §3.2.2). Indeks = kode. */
export const URI_PREFIXES = [
  '', 'http://www.', 'https://www.', 'http://', 'https://', 'tel:', 'mailto:', 'ftp://anonymous:anonymous@',
  'ftp://ftp.', 'ftps://', 'sftp://', 'smb://', 'nfs://', 'ftp://', 'dav://', 'news:', 'telnet://', 'imap:',
  'rtsp://', 'urn:', 'pop:', 'sip:', 'sips:', 'tftp:', 'btspp://', 'btl2cap://', 'btgoep://', 'tcpobex://',
  'irdaobex://', 'file://', 'urn:epc:id:', 'urn:epc:tag:', 'urn:epc:pat:', 'urn:epc:raw:', 'urn:epc:', 'urn:nfc:',
]

const TNF_WELL_KNOWN = 0x01
const MB = 0x80
const ME = 0x40
const SR = 0x10

/** Prefiks terpanjang yang cocok → [kode, sisa URL]. */
export function splitUriPrefix(url) {
  let best = 0
  for (let code = 1; code < URI_PREFIXES.length; code++) {
    const p = URI_PREFIXES[code]
    if (url.startsWith(p) && p.length > URI_PREFIXES[best].length) best = code
  }
  return [best, url.slice(URI_PREFIXES[best].length)]
}

/** Satu record NDEF URI (MB+ME, short record bila payload < 256 byte). */
export function encodeUriRecord(url) {
  const [code, rest] = splitUriPrefix(String(url))
  const payload = Buffer.concat([Buffer.from([code]), Buffer.from(rest, 'utf8')])
  const type = Buffer.from('U')
  if (payload.length < 256) {
    return Buffer.concat([Buffer.from([MB | ME | SR | TNF_WELL_KNOWN, type.length, payload.length]), type, payload])
  }
  const len = Buffer.alloc(4)
  len.writeUInt32BE(payload.length)
  return Buffer.concat([Buffer.from([MB | ME | TNF_WELL_KNOWN, type.length]), len, type, payload])
}

/** TLV NDEF Message (0x03) + Terminator (0xFE). Panjang ≥ 255 memakai format 3 byte (0xFF hi lo). */
export function ndefTlv(message) {
  const n = message.length
  if (n > 0xfffe) throw new Error('Pesan NDEF terlalu panjang')
  const head = n < 0xff ? Buffer.from([0x03, n]) : Buffer.from([0x03, 0xff, n >> 8, n & 0xff])
  return Buffer.concat([head, message, Buffer.from([0xfe])])
}

export const encodeUrlTlv = (url) => ndefTlv(encodeUriRecord(url))

/**
 * Cari TLV NDEF Message di area data (mulai halaman 4) dan kembalikan URL record URI pertama, atau null.
 * TLV Null (0x00) dilewati; Lock/Memory Control (0x01/0x02) dilompati sesuai panjangnya.
 */
export function parseUrlFromTlv(data) {
  let i = 0
  while (i < data.length) {
    const t = data[i]
    if (t === 0x00) { i++; continue }
    if (t === 0xfe) return null
    if (i + 1 >= data.length) return null
    let len = data[i + 1]
    let start = i + 2
    if (len === 0xff) {
      if (i + 3 >= data.length) return null
      len = (data[i + 2] << 8) | data[i + 3]
      start = i + 4
    }
    if (t === 0x03) {
      if (start + len > data.length) return null
      return parseUriRecord(data.subarray(start, start + len))
    }
    i = start + len
  }
  return null
}

/** URL dari record URI pertama dalam pesan NDEF, atau null. */
export function parseUriRecord(msg) {
  if (msg.length < 3) return null
  const header = msg[0]
  const typeLen = msg[1]
  const sr = (header & SR) !== 0
  const il = (header & 0x08) !== 0
  let p = 2
  let payloadLen
  if (sr) { payloadLen = msg[p]; p += 1 } else {
    if (msg.length < p + 4) return null
    payloadLen = msg.readUInt32BE(p); p += 4
  }
  const idLen = il ? msg[p++] : 0
  const type = msg.subarray(p, p + typeLen).toString('latin1')
  p += typeLen + idLen
  if ((header & 0x07) !== TNF_WELL_KNOWN || type !== 'U' || p + payloadLen > msg.length || payloadLen < 1) return null
  const payload = msg.subarray(p, p + payloadLen)
  const prefix = URI_PREFIXES[payload[0]] ?? ''
  return prefix + payload.subarray(1).toString('utf8')
}
