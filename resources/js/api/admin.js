import { http } from './client'

/** Endpoint panel pemilik di panel.{base} (BSD §8.5). */
export const adminApi = {
  me: () => http.get('/api/me'),
  changePassword: (body) => http.put('/api/me/password', body),
  dashboard: () => http.get('/api/dashboard'),

  tenants: (params) => http.get('/api/tenants', params),
  tenant: (id) => http.get(`/api/tenants/${id}`),
  tenantMembers: (id, params) => http.get(`/api/tenants/${id}/members`, params),
  tenantCards: (id, params) => http.get(`/api/tenants/${id}/cards`, params),
  updateTenant: (id, body) => http.patch(`/api/tenants/${id}`, body),
  suspendTenant: (id, reason) => http.post(`/api/tenants/${id}/suspend`, { reason }),
  reactivateTenant: (id) => http.post(`/api/tenants/${id}/reactivate`),

  users: (params) => http.get('/api/users', params),
  user: (id) => http.get(`/api/users/${id}`),
  userCards: (id, params) => http.get(`/api/users/${id}/cards`, params),
  blockUser: (id) => http.post(`/api/users/${id}/block`),
  unblockUser: (id) => http.post(`/api/users/${id}/unblock`),
  logoutUser: (id) => http.post(`/api/users/${id}/logout-all`),

  cards: (params) => http.get('/api/cards', params),
  card: (token) => http.get(`/api/cards/${token}`),
  cardStatus: (token, status, reason) => http.post(`/api/cards/${token}/status`, { status, reason }),
  reissueCode: (token) => http.post(`/api/cards/${token}/reissue-code`),
  encoded: (token, uid) => http.post(`/api/cards/${token}/encoded`, { uid }),
  qc: (token, body) => http.post(`/api/cards/${token}/qc`, body),
  replacementBatch: (token) => http.post(`/api/cards/${token}/replacement-batch`),

  batches: (params) => http.get('/api/batches', params),
  createBatch: (body) => http.post('/api/batches', body),
  batch: (id) => http.get(`/api/batches/${id}`),
  batchCards: (id, params) => http.get(`/api/batches/${id}/cards`, params),
  generateBatch: (id) => http.post(`/api/batches/${id}/generate`),
  batchPrint: (id) => http.get(`/api/batches/${id}/print`),
  batchStatus: (id, status) => http.post(`/api/batches/${id}/status`, { status }),
  shipBatch: (id, trackingNumber) => http.post(`/api/batches/${id}/ship`, { tracking_number: trackingNumber }),
  bundleUrl: (id) => `/api/batches/${id}/bundle.csv`,
  nextCard: (id, mode, after) => http.get(`/api/batches/${id}/next`, { mode, after }),
  stationBatches: () => http.get('/api/stations/batches'),

  staff: (params) => http.get('/api/staff', params),
  inviteStaff: (email, roleId) => http.post('/api/staff', { email, role_id: roleId }),
  updateStaff: (id, body) => http.patch(`/api/staff/${id}`, body),
  resetStaffPassword: (id) => http.post(`/api/staff/${id}/reset-password`),

  orders: (params) => http.get('/api/orders', params),
  order: (id) => http.get(`/api/orders/${id}`),
  startProduction: (id) => http.post(`/api/orders/${id}/production`),
  shipOrder: (id, body) => http.post(`/api/orders/${id}/ship`, body),
  deliverOrder: (id) => http.post(`/api/orders/${id}/deliver`),
  cancelOrder: (id, reason) => http.post(`/api/orders/${id}/cancel`, { reason }),

  plans: () => http.get('/api/plans'),
  createPlan: (body) => http.post('/api/plans', body),
  updatePlan: (key, body) => http.patch(`/api/plans/${key}`, body),

  financeSummary: () => http.get('/api/finance/summary'),
  financeInvoices: (params) => http.get('/api/finance/invoices', params),
  financeInvoice: (id) => http.get(`/api/finance/invoices/${id}`),
  markInvoicePaid: (id, body) => http.post(`/api/finance/invoices/${id}/mark-paid`, body),
  voidInvoice: (id, reason) => http.post(`/api/finance/invoices/${id}/void`, { reason }),
  paymentEvents: (params) => http.get('/api/finance/events', params),
  subscriptions: (params) => http.get('/api/finance/subscriptions', params),
  grantPlan: (body) => http.post('/api/finance/grant', body),

  vouchers: (params) => http.get('/api/vouchers', params),
  createVoucher: (body) => http.post('/api/vouchers', body),
  updateVoucher: (id, body) => http.patch(`/api/vouchers/${id}`, body),

  domains: (params) => http.get('/api/domains', params),
  addDomain: (tenant, domain) => http.post('/api/domains', { tenant, domain }),
  verifyDomain: (id) => http.post(`/api/domains/${id}/verify`),
  setDomainStatus: (id, status) => http.patch(`/api/domains/${id}`, { status }),
  removeDomain: (id) => http.del(`/api/domains/${id}`),

  catalog: () => http.get('/api/catalog'),
  saveLanyardWidth: (id, body) => (id ? http.patch(`/api/catalog/lanyard-widths/${id}`, body) : http.post('/api/catalog/lanyard-widths', body)),
  saveLanyardHardware: (id, body) => (id ? http.patch(`/api/catalog/lanyard-hardware/${id}`, body) : http.post('/api/catalog/lanyard-hardware', body)),
  saveCardProduct: (id, body) => (id ? http.patch(`/api/catalog/card-products/${id}`, body) : http.post('/api/catalog/card-products', body)),
  saveShipping: (id, body) => (id ? http.patch(`/api/catalog/shipping/${id}`, body) : http.post('/api/catalog/shipping', body)),
  updateAddon: (id, body) => http.patch(`/api/catalog/addons/${id}`, body),
  updateContactType: (id, body) => http.patch(`/api/catalog/contact-types/${id}`, body),

  announcements: (params) => http.get('/api/announcements', params),
  sendAnnouncement: (body) => http.post('/api/announcements', body),

  audit: (params) => http.get('/api/audit', params),
  system: () => http.get('/api/system'),
  saveSettings: (body) => http.put('/api/system/settings', body),
}
