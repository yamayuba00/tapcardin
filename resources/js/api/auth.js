import { http } from './client'

export const authApi = {
  /** app. — email + password (+ ALTCHA), "ingat saya" 7 hari */
  login: (body) => http.post('/api/auth/login', body),
  /** daftar manual: kirim kode verifikasi ke email, lalu verify membuat akun & langsung masuk */
  register: (body) => http.post('/api/auth/register', body),
  verifyRegistration: (body) => http.post('/api/auth/register/verify', body),
  forgotPassword: (body) => http.post('/api/auth/password/forgot', body),
  resetPassword: (body) => http.post('/api/auth/password/reset', body),
  logout: () => http.post('/api/auth/logout'),
  /** panel. — staf login dengan email + password */
  adminLogin: (email, password) => http.post('/api/auth/login', { email, password }),
}
