// status.{base} — halaman status publik: kondisi layanan, uptime 90 hari, info gangguan & maintenance (realtime).
export default [
  { path: '/', name: 'status', component: () => import('@/pages/status/Status/index.vue'), meta: { title: 'Status layanan' } },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]
