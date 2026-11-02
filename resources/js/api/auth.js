import { http } from './client'

export const authApi = {
  /** app. — kartu NFC (token + UID dari Web NFC) + PIN 6 digit (+ ALTCHA), "ingat saya" 7 hari */
  cardLogin: (body) => http.post('/api/auth/card', body),
  /** hanya APP_ENV=local: masuk sebagai akun dummy */
  devLogin: (email) => http.post('/api/auth/dev', { email }),
  logout: () => http.post('/api/auth/logout'),
  /** panel. — staf login dengan email + password */
  adminLogin: (email, password) => http.post('/api/auth/login', { email, password }),
}
