import { http } from './client'

export const authApi = {
  requestCode: (email) => http.post('/api/auth/otp/request', { email }),
  verify: (email, code) => http.post('/api/auth/otp/verify', { email, code }),
  logout: () => http.post('/api/auth/logout'),
  /** admin. — staf login dengan email + password */
  adminLogin: (email, password) => http.post('/api/auth/login', { email, password }),
}
