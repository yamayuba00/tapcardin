import { http } from './client'

export const authApi = {
  /** app. — kartu NFC (token + UID dari Web NFC atau kunci chip dari tap) + kode akun 6 angka (+ ALTCHA), "ingat saya" 7 hari */
  cardLogin: (body) => http.post('/api/auth/card', body),
  /** hanya APP_ENV=local: masuk sebagai akun dummy */
  devLogin: (email) => http.post('/api/auth/dev', { email }),
  logout: () => http.post('/api/auth/logout'),
  /** host perusahaan — email + kata sandi anggota workspace itu (di app. ditolak) */
  passwordLogin: (email, password) => http.post('/api/auth/password', { email, password }),
  /** host perusahaan — info undangan untuk halaman aktivasi (tanpa email) */
  inviteInfo: (token) => http.get(`/api/invites/${token}`),
  /** host perusahaan — aktivasi undangan: email harus sama dengan undangan, lalu kata sandi baru */
  acceptInvite: (token, body) => http.post(`/api/invites/${token}/accept`, body),
  /** panel. — staf login dengan email + password */
  adminLogin: (email, password) => http.post('/api/auth/login', { email, password }),
  /** panel. — kirim tautan reset password ke email staf (jawaban selalu sama) */
  forgotPassword: (email) => http.post('/api/auth/forgot-password', { email }),
  resetPassword: (body) => http.post('/api/auth/reset-password', body),
}
