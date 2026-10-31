// domain utama — situs marketing
export default [
  { path: '/', name: 'home', component: () => import('@/pages/marketing/Home/index.vue'), meta: { title: 'TapCard — Satu sentuhan, langsung terhubung' } },
  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/pages/public/NotFound/index.vue') },
]
