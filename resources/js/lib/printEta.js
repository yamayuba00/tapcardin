// Estimasi cetak: tanggal dari server (YYYY-MM-DD, zona WIB) → teks tampilan "12 Des 2026 – 16 Des 2026".
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des']

export function formatPrintDate(iso) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso ?? '')
  if (!m) return null
  const month = Number(m[2])
  if (month < 1 || month > 12) return null
  return `${Number(m[3])} ${MONTHS[month - 1]} ${m[1]}`
}

// Rentang: "a – b"; satu tanggal saja bila sama; null bila salah satu tanggal kosong/tidak valid.
export function formatPrintRange(min, max) {
  const a = formatPrintDate(min)
  const b = formatPrintDate(max)
  if (!a || !b) return null
  return a === b ? a : `${a} – ${b}`
}
