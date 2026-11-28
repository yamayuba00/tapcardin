// domain utama — situs marketing
export default [
  { path: '/', name: 'home', component: () => import('@/pages/marketing/Home/index.vue'), meta: { title: 'TapCard — Satu sentuhan, langsung terhubung' } },
  // tautan berhenti berlangganan email blast (URL bertanda tangan) — konfirmasi lalu POST ke URL yang sama
  { path: '/unsubscribe', name: 'unsubscribe', component: () => import('@/pages/marketing/Unsubscribe/index.vue'), meta: { title: 'Berhenti berlangganan — TapCard' } },
  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/pages/public/NotFound/index.vue') },
]
