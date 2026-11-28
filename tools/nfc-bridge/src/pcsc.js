// Transport PC/SC lewat nfc-pcsc (@pokusew/pcsclite, modul native). Satu-satunya berkas yang butuh perangkat keras.
import { EventEmitter } from 'node:events'
import { createRequire } from 'node:module'
import { readUid, TagError } from './tag.js'

/** ACR1252U & sejenisnya memunculkan 2 reader: "PICC" (antena kartu) dan "SAM" (slot modul) — pakai PICC. */
export function pickReader(current, name, filter = '') {
  if (/\bSAM\b/i.test(name)) return false
  if (filter && !name.toLowerCase().includes(filter.toLowerCase())) return false
  return !current || (/PICC/i.test(name) && !/PICC/i.test(current))
}

export function createPcscTransport({ readerFilter = '', log = () => {} } = {}) {
  const require = createRequire(import.meta.url)
  let NFC
  try {
    ;({ NFC } = require('nfc-pcsc'))
  } catch (e) {
    throw new Error(`Modul nfc-pcsc belum terpasang atau gagal dibangun (${e.message}). Jalankan "npm install" di tools/nfc-bridge — lihat README.`)
  }
  const em = new EventEmitter()
  let reader = null
  let card = null
  let queue = Promise.resolve()

  // semua APDU diantrikan: baca UID otomatis saat kartu ditempel tidak boleh bertabrakan dengan proses tulis
  const transmit = (apdu) => {
    const run = queue.then(() => {
      if (!reader || !card) throw new TagError('no_card', 'Tidak ada kartu di reader.')
      return reader.transmit(apdu, 64)
    })
    queue = run.catch(() => {})
    return run
  }

  const nfc = new NFC()
  nfc.on('reader', (r) => {
    if (!pickReader(reader?.name, r.name, readerFilter)) { log(`reader diabaikan: ${r.name}`); return }
    reader = r
    card = null
    r.autoProcessing = false
    log(`reader: ${r.name}`)
    em.emit('reader', r.name)
    r.on('card', async () => {
      if (reader !== r) return
      card = {}
      try { card.uid = await readUid(transmit) } catch { /* kartu non-NTAG: UID tetap dicoba saat tulis */ }
      em.emit('card', { present: true, uid: card?.uid ?? null })
    })
    r.on('card.off', () => {
      if (reader !== r) return
      card = null
      em.emit('card', { present: false })
    })
    r.on('error', (e) => log(`galat reader: ${e?.message ?? e}`))
    r.on('end', () => {
      if (reader !== r) return
      reader = null
      card = null
      log(`reader dicabut: ${r.name}`)
      em.emit('reader', null)
    })
  })
  nfc.on('error', (e) => log(`galat PC/SC: ${e?.message ?? e}`))

  return Object.assign(em, {
    status: () => ({ reader: reader?.name ?? null, card: !!card }),
    transmit,
    waitForCard(ms) {
      if (card) return Promise.resolve()
      return new Promise((resolve, reject) => {
        const onCard = (c) => { if (c.present) { cleanup(); resolve() } }
        const t = setTimeout(() => { cleanup(); reject(new TagError('no_card', 'Tidak ada kartu terdeteksi. Letakkan kartu di atas reader lalu tekan Tulis lagi.')) }, ms)
        const cleanup = () => { clearTimeout(t); em.off('card', onCard) }
        em.on('card', onCard)
      })
    },
    close: () => nfc.close?.(),
  })
}
