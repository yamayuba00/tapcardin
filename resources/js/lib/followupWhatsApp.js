// Tautan WhatsApp untuk tombol di daftar follow-up. Nomor dinormalisasi ke format internasional (62…) lewat
// toE164 (sama dengan App\Support\Phone); hanya nomor seluler Indonesia (62 8…) yang menghasilkan tautan.
import { toE164 } from './format.js'

/**
 * Isi pesan awal. SENGAJA KOSONG: wording dari pemilik produk belum ada.
 * Kosong → tautan tanpa parameter `text`; isi string ini untuk menambahkan pesan awal.
 */
export const FOLLOWUP_WA_TEMPLATE = ''

/**
 * @param {string|null|undefined} phone nomor lead (mis. +6281234567890 atau 081234567890)
 * @param {string} [template] pesan awal; default FOLLOWUP_WA_TEMPLATE
 * @returns {string|null} URL wa.me, atau null bila nomor bukan seluler Indonesia yang valid
 */
export function followupWhatsAppUrl(phone, template = FOLLOWUP_WA_TEMPLATE) {
  const e164 = toE164(phone ? String(phone) : null)
  const digits = e164 ? e164.slice(1) : ''
  if (!/^628\d{7,10}$/.test(digits)) return null
  const text = template ? `?text=${encodeURIComponent(template)}` : ''
  return `https://wa.me/${digits}${text}`
}
