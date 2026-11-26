// Helper dashboard panel pemilik — murni, diuji dengan `node --test`. Memakai helper beranda (lib/dashboard.js) tanpa mengubahnya.
import { deltaPill } from './dashboard.js'

/**
 * Jumlah n hari terakhir dan n hari sebelumnya dari deret harian (urut lama → baru).
 * previous = null bila deret tidak cukup panjang untuk membandingkan (tidak mengarang pembanding).
 */
export function periodSums(series = [], days = 7) {
  const vals = series.map((p) => Number(p?.value) || 0)
  const sum = (a) => a.reduce((x, y) => x + y, 0)
  const current = sum(vals.slice(-days))
  const previous = vals.length >= days * 2 ? sum(vals.slice(-days * 2, -days)) : null
  return { current, previous }
}

/** Pil delta untuk periode n hari terakhir vs n hari sebelumnya; null bila tidak ada pembanding. */
export function periodDelta(series = [], days = 7) {
  const { current, previous } = periodSums(series, days)
  return previous === null ? null : deltaPill(current, previous)
}

/**
 * Daftar "Perlu tindak lanjut" dari GET /api/dashboard. Hitungan null = tidak berwenang (disembunyikan), 0 = kosong.
 * Mengembalikan item { key, label, sub, n, to } hanya untuk hitungan > 0, urut sesuai prioritas.
 */
export function attentionItems(m) {
  if (!m) return []
  const a = m.attention ?? {}
  const r = m.risk ?? {}
  const rows = [
    { key: 'payment_reviews', label: 'Pembayaran bermasalah', sub: 'Tinjauan pembayaran terbuka', n: a.payment_reviews, to: '/approvals?tab=reviews' },
    { key: 'approvals', label: 'Persetujuan keuangan', sub: 'Permintaan menunggu persetujuan', n: a.approvals, to: '/approvals' },
    { key: 'refunds_decide', label: 'Refund menunggu keputusan', sub: 'Belum diputuskan', n: a.refunds_decide, to: '/approvals?tab=refunds' },
    { key: 'refunds_payout', label: 'Refund menunggu transfer', sub: 'Disetujui, belum dicatat', n: a.refunds_payout, to: '/approvals?tab=refunds' },
    { key: 'orders_review', label: 'Pesanan bertanda risiko', sub: 'Menunggu tinjauan', n: a.orders_review, to: '/orders?status=paid' },
    { key: 'invoices_failed', label: 'Invoice gagal atau terlambat', sub: 'Lewat jatuh tempo', n: r.invoices_failed, to: '/finance?tab=invoices' },
    { key: 'subscriptions_ending', label: 'Langganan habis dalam 7 hari', sub: 'Aktif, uji coba, atau tenggang', n: r.subscriptions_ending, to: '/finance?tab=subscriptions' },
    { key: 'domains_problem', label: 'Domain bermasalah', sub: 'Menunggu DNS atau dinonaktifkan', n: r.domains_problem, to: '/domains' },
    { key: 'replacements', label: 'Kartu hilang menunggu pengganti', sub: 'Belum masuk batch pengganti', n: m.replacements_pending, to: '/cards?replacement=1' },
  ]
  return rows.filter((x) => typeof x.n === 'number' && x.n > 0)
}
