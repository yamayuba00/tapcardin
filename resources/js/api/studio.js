import { http } from './client'

/** Design Studio (PRD §10.3) & editor lanyard di workspace. */
export const studioApi = {
  catalog: () => http.get('/api/studio/templates'),
  list: (kind) => http.get('/api/designs', kind ? { kind } : undefined),
  create: (body) => http.post('/api/designs', body),
  get: (id) => http.get(`/api/designs/${id}`),
  save: (id, body) => http.patch(`/api/designs/${id}`, body),
  remove: (id) => http.del(`/api/designs/${id}`),
  validate: (id) => http.post(`/api/designs/${id}/validate`),
  uploadImage: (id, file) => {
    const fd = new FormData()
    fd.append('image', file)
    return http.post(`/api/designs/${id}/assets`, fd)
  },
  /** Buat (atau buka) lanyard pasangan kartu — brand kit bersama, slot lanyard kartu otomatis aktif. */
  pairLanyard: (cardId) => http.post(`/api/designs/${cardId}/lanyard`),
  saveAsTemplate: (id, name) => http.post(`/api/designs/${id}/template`, { name }),
  lanyardQuote: (params) => http.get('/api/lanyards/quote', params),
}

/** Brand kit bersama kartu & lanyard. */
export const kitsApi = {
  list: () => http.get('/api/brand-kits'),
  create: (body) => http.post('/api/brand-kits', body),
  update: (id, body) => http.patch(`/api/brand-kits/${id}`, body),
  remove: (id) => http.del(`/api/brand-kits/${id}`),
  logo: (id, file) => {
    const fd = new FormData()
    fd.append('image', file)
    return http.post(`/api/brand-kits/${id}/logo`, fd)
  },
}
