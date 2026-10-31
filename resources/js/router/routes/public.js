// tap.{base} — resolver permanen yang tercetak di chip & QR (AFD §2, layar P-01…P-07)
// CardResolver dimuat langsung (bukan lazy): halaman yang dibuka setiap tap, tanpa round-trip chunk tambahan.
import CardResolver from '@/pages/public/CardResolver/index.vue'

export default [
  { path: '/c/:token', name: 'card', component: CardResolver, meta: { title: null } },
  { path: '/q/:qr', name: 'qr-expired', component: () => import('@/pages/public/QrExpired/index.vue'), meta: { title: 'QR sudah kedaluwarsa' } },
  { path: '/k', name: 'shortcode', component: () => import('@/pages/public/ShortCode/index.vue'), meta: { title: 'Masukkan kode kartu' } },
  { path: '/@:handle', name: 'handle', component: () => import('@/pages/public/HandleRedirect/index.vue') },
  { path: '/', redirect: () => ({ path: '/k' }) },
  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/pages/public/NotFound/index.vue'), meta: { title: 'Tidak ditemukan' } },
]
