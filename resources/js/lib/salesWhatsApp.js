// Tautan WhatsApp tim sales dengan pesan awal yang menyebut asal klik (bagian halaman / paket).
const PLACES = {
  hero: 'halaman utama',
  floating: 'tombol Hubungi Sales',
  company: 'bagian Untuk Perusahaan',
  pricing: 'tabel harga',
  closing: 'akhir halaman',
}

/** @param {string} number nomor internasional tanpa + (mis. 6281234567890); kosong → null */
export function salesWhatsAppUrl(number, place, plan = '') {
  const digits = String(number || '').replace(/\D/g, '')
  if (!digits) return null
  const from = PLACES[place] ?? 'situs'
  const text = `Halo TapCard, saya tertarik ${plan ? `paket ${plan}` : 'TapCard untuk tim/perusahaan'}. Saya menghubungi dari ${from}.`
  return `https://wa.me/${digits}?text=${encodeURIComponent(text)}`
}
