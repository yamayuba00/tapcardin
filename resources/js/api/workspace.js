import { http } from './client'

/** Endpoint workspace di app.{base} & {slug}.{base} (BSD §8.4). */
export const meApi = {
  get: () => http.get('/api/me'),
  update: (body) => http.patch('/api/me', body),
  settings: () => http.get('/api/me/settings'),
  destroy: (confirmEmail) => http.del('/api/me', { confirm_email: confirmEmail }),
  onboarding: (dismissed) => http.post('/api/me/onboarding', { dismissed }),
  exportUrl: '/api/me/export',
}

export const addonsApi = {
  list: () => http.get('/api/addons'),
  interest: (key, note = null) => http.post(`/api/addons/${key}/interest`, { note }),
  withdraw: (key) => http.del(`/api/addons/${key}/interest`),
}

export const dashboardApi = {
  summary: () => http.get('/api/dashboard'),
}

export const cardsApi = {
  list: () => http.get('/api/cards'),
  createDigital: () => http.post('/api/cards/digital'),
  get: (token) => http.get(`/api/cards/${token}`),
  visual: (token) => http.get(`/api/cards/${token}/visual`),
  setDesign: (token, body) => http.put(`/api/cards/${token}/design`, body),
  /** Catat aksi berbagi (copy | whatsapp | native | download | qr) — gagal diam-diam, tidak mengganggu pengguna. */
  shared: (token, channel) => http.post(`/api/cards/${token}/shared`, { channel }).catch(() => null),
  update: (token, body) => http.patch(`/api/cards/${token}`, body),
  activation: (token) => http.get(`/api/cards/${token}/activation`),
  activate: (token, code) => http.post('/api/cards/activate', { token, code }),
  claim: (shortCode, code) => http.post('/api/cards/claim', { short_code: shortCode, code }),
  requestReplacement: (token, note) => http.post(`/api/cards/${token}/replacement`, { note }),
  dynamicQr: (token) => http.post(`/api/cards/${token}/dynamic-qr`),
}

export const profilesApi = {
  list: () => http.get('/api/profiles'),
  create: (label) => http.post('/api/profiles', { label }),
  get: (id) => http.get(`/api/profiles/${id}`),
  preview: (id) => http.get(`/api/profiles/${id}/preview`),
  update: (id, body) => http.patch(`/api/profiles/${id}`, body),
  remove: (id) => http.del(`/api/profiles/${id}`),
  saveFields: (id, fields) => http.put(`/api/profiles/${id}/fields`, { fields }),
  themes: () => http.get('/api/bio/themes'),
  contactTypes: () => http.get('/api/contact-types'),
  fieldImage: (id, file) => {
    const fd = new FormData()
    fd.append('image', file)
    return http.post(`/api/profiles/${id}/field-image`, fd)
  },
  uploadAvatar: (id, file) => {
    const fd = new FormData()
    fd.append('photo', file)
    return http.post(`/api/profiles/${id}/avatar`, fd)
  },
}

export const leadsApi = {
  list: (params) => http.get('/api/leads', params),
  create: (body) => http.post('/api/leads', body),
  get: (id) => http.get(`/api/leads/${id}`),
  update: (id, body) => http.patch(`/api/leads/${id}`, body),
  remove: (id) => http.del(`/api/leads/${id}`),
  stage: (id, stageId, lostReason) => http.post(`/api/leads/${id}/stage`, { stage_id: stageId, lost_reason: lostReason }),
  activity: (id, type, body) => http.post(`/api/leads/${id}/activities`, { type, body }),
  stages: () => http.get('/api/pipeline/stages'),
  tags: () => http.get('/api/leads/tags'),
  exportUrl: '/api/leads/export.csv',
  addReminder: (id, dueAt, note) => http.post(`/api/leads/${id}/reminders`, { due_at: dueAt, note }),
  dashboard: (days) => http.get('/api/leads/dashboard', days ? { days } : undefined),
  saveDashboard: (widgets, days) => http.put('/api/leads/dashboard/layout', { widgets, days }),
}

/** LD-05 — pengingat follow-up milik pengguna. */
export const remindersApi = {
  list: (params) => http.get('/api/reminders', params),
  update: (id, body) => http.patch(`/api/reminders/${id}`, body),
  remove: (id) => http.del(`/api/reminders/${id}`),
}

/** LD-10 — impor CSV koneksi. */
export const importsApi = {
  list: (params) => http.get('/api/leads/imports', params),
  upload: (file) => {
    const fd = new FormData()
    fd.append('file', file)
    return http.post('/api/leads/imports', fd)
  },
  get: (id) => http.get(`/api/leads/imports/${id}`),
  start: (id, mapping) => http.post(`/api/leads/imports/${id}/start`, { mapping }),
  remove: (id) => http.del(`/api/leads/imports/${id}`),
}

/** F-10 — Mode Event. */
export const eventsApi = {
  list: (params) => http.get('/api/events', params),
  create: (body) => http.post('/api/events', body),
  get: (id) => http.get(`/api/events/${id}`),
  update: (id, body) => http.patch(`/api/events/${id}`, body),
  remove: (id) => http.del(`/api/events/${id}`),
  activate: (id) => http.post(`/api/events/${id}/activate`),
  deactivate: () => http.del('/api/events/active'),
}

/** F-09 — Mode Reader. */
export const scansApi = {
  /** value = URL hasil NFC/QR, kode pendek, atau token. */
  save: (body) => http.post('/api/scans', body),
  inbound: () => http.get('/api/scans/inbound'),
}

export const analyticsApi = {
  cards: (days) => http.get('/api/analytics/cards', { days }),
}

export const notificationsApi = {
  list: (since) => http.get('/api/notifications', since ? { since } : undefined),
  markRead: () => http.post('/api/notifications/read'),
  streamUrl: '/api/stream',
}

/** OR-05 — paket & tagihan. */
export const ordersApi = {
  catalog: () => http.get('/api/orders/catalog'),
  list: (params) => http.get('/api/orders', params),
  get: (id) => http.get(`/api/orders/${id}`),
  create: (body) => http.post('/api/orders', body),
  pay: (id) => http.post(`/api/orders/${id}/pay`),
  check: (id) => http.post(`/api/orders/${id}/check`),
  cancel: (id) => http.post(`/api/orders/${id}/cancel`),
}

export const billingApi = {
  get: () => http.get('/api/billing'),
  checkout: (plan, cycle, voucher = null) => http.post('/api/billing/checkout', { plan, cycle, voucher }),
  voucher: (code, plan, cycle) => http.post('/api/billing/voucher', { code, plan, cycle }),
  invoices: (params) => http.get('/api/billing/invoices', params),
  invoice: (id) => http.get(`/api/billing/invoices/${id}`),
  pay: (id) => http.post(`/api/billing/invoices/${id}/pay`),
  /** tanya status ke gateway pembayaran (setelah kembali dari halaman bayar) */
  check: (id) => http.post(`/api/billing/invoices/${id}/check`),
  simulate: (id, result, method) => http.post(`/api/billing/invoices/${id}/simulate`, { result, method }),
  cancel: (resume = false) => http.post('/api/billing/cancel', { resume }),
}
