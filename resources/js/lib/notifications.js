// Notifikasi: teks manusiawi & tautan tujuan per tipe — dipakai toast, lonceng, dan halaman Notifikasi.

export const TYPES = ['tap', 'lead_new', 'lost_card_tapped', 'saved_back', 'reminder_due', 'import_done', 'invoice_paid', 'invoice_due', 'plan_changed', 'workspace_expired', 'order_status', 'announcement', 'tap_quota', 'subscription_past_due', 'grace_ending', 'subscription_reminder', 'trial_ending', 'order_unpaid', 'webhook_disabled', 'lead_assigned']

export const messages = {
  tap: (d) => `Seseorang membuka profil Anda${d.city ? ' · ' + d.city : ''}`,
  lead_new: (d) => `${d.name} terhubung${d.company ? ' · ' + d.company : ''}`,
  lead_assigned: (d) => (d.count > 1 ? `${d.by} menugaskan ${d.count} lead ke Anda` : `${d.by} menugaskan ${d.name} ke Anda`),
  lost_card_tapped: (d) => `Kartu hilang Anda baru di-tap${d.city ? ' di ' + d.city : ''}`,
  saved_back: (d) => `${d.name} menyimpan Anda sebagai koneksi. Buka Scan untuk simpan balik.`,
  reminder_due: (d) => `Waktunya follow-up ${d.name}${d.note ? ' · ' + d.note : ''}`,
  invoice_paid: (d) => (d.label ? `Pembayaran ${d.number} berhasil — ${d.label} aktif` : `Pembayaran ${d.number} berhasil — paket ${d.plan} aktif`),
  tap_quota: (d) => (d.level >= 100
    ? `Kuota tap kartu digital habis (${d.used}/${d.quota}) — profil tidak bisa dibuka. Tambah tap di Pengaturan.`
    : `Kuota tap kartu digital tinggal sedikit (${d.used}/${d.quota}). Tambah tap di Pengaturan.`),
  invoice_due: (d) => `Tagihan perpanjangan ${d.number} sudah terbit`,
  plan_changed: (d) => `Paket workspace kini ${d.plan}`,
  subscription_past_due: (d) => `Paket ${d.plan} masuk masa tenggang — bayar tagihan agar fitur tidak terkunci`,
  grace_ending: (d) => (d.company
    ? `Masa tenggang paket ${d.plan} hampir berakhir — bayar sekarang agar dashboard tidak terkunci`
    : `Masa tenggang paket ${d.plan} hampir berakhir — bayar sekarang agar tidak turun ke paket gratis`),
  subscription_reminder: (d) => `Masa aktif ${d.workspace ?? 'workspace'} berakhir ${d.days <= 1 ? 'besok' : `${d.days} hari lagi`} — perpanjang paket ${d.plan} agar dashboard tidak terkunci`,
  trial_ending: (d) => `Uji coba ${d.workspace ?? 'workspace'} berakhir besok — pilih paket agar dashboard tidak terkunci`,
  workspace_expired: (d) => `Masa aktif paket ${d.plan} habis — dashboard terkunci sampai diperpanjang`,
  webhook_disabled: (d) => `Webhook ke ${d.url} dinonaktifkan otomatis setelah ${d.failures ?? 20} kali gagal — periksa penerimanya`,
  order_unpaid: (d) => `Pesanan ${d.number} belum dibayar — batal otomatis bila tidak dibayar tepat waktu`,
  order_status: (d) => `Pesanan ${d.number}: ${d.label}${d.tracking ? ' · resi ' + d.tracking : ''}`,
  announcement: (d) => `${d.title} — ${d.body}`,
  import_done: (d) => `Impor ${d.filename} selesai: ${d.created} baru, ${d.merged} digabung, ${d.failed} gagal`,
}

/** Teks satu notifikasi; null bila tipe tidak dikenal. */
export function message(type, data = {}) {
  return messages[type]?.(data ?? {}) ?? null
}

/** Halaman tujuan saat notifikasi dibuka (null = tanpa tautan). */
export function link(type, d = {}) {
  d = d ?? {}
  switch (type) {
    case 'tap':
    case 'lost_card_tapped':
    case 'tap_quota':
      return d.card ? `/cards/${d.card}` : '/cards'
    case 'lead_new':
    case 'lead_assigned':
    case 'reminder_due':
      return d.id ? `/connections/${d.id}` : type === 'reminder_due' ? '/follow-up' : '/connections'
    case 'saved_back':
      return '/scan'
    case 'import_done':
      return '/connections'
    case 'invoice_paid':
    case 'invoice_due':
      return d.id ? `/billing/invoices/${d.id}` : '/billing'
    case 'plan_changed':
    case 'subscription_past_due':
    case 'grace_ending':
    case 'subscription_reminder':
    case 'trial_ending':
    case 'workspace_expired':
      return '/billing'
    case 'order_status':
    case 'order_unpaid':
      return d.id ? `/orders/${d.id}` : '/orders'
    case 'webhook_disabled':
      return '/webhooks'
    default:
      return null
  }
}

/** Kelompok untuk filter & ikon: tap | lead | billing | order | system. */
export function group(type) {
  if (['tap', 'lost_card_tapped', 'tap_quota'].includes(type)) return 'tap'
  if (['lead_new', 'lead_assigned', 'reminder_due', 'saved_back', 'import_done'].includes(type)) return 'lead'
  if (['invoice_paid', 'invoice_due', 'plan_changed', 'subscription_past_due', 'grace_ending', 'subscription_reminder', 'trial_ending', 'workspace_expired'].includes(type)) return 'billing'
  if (['order_status', 'order_unpaid'].includes(type)) return 'order'
  return 'system'
}
