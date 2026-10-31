// Status pesanan → warna badge (label dari server). Urutan langkah untuk linimasa.
export const ORDER_STATUS = {
  pending_payment: { tone: 'warn' },
  paid: { tone: 'info' },
  in_production: { tone: 'info' },
  shipped: { tone: 'info' },
  delivered: { tone: 'ok' },
  cancelled: { tone: 'muted' },
}

export const ORDER_STEPS = ['created', 'paid', 'in_production', 'shipped', 'delivered']
