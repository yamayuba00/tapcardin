// wbh.{base} — dokumentasi publik webhook keluar untuk developer (webhook masuk dari gateway juga di host ini, server saja).
export default [
  { path: '/', name: 'webhook-docs', component: () => import('@/pages/webhooks/Developers/index.vue'), meta: { title: 'Webhook untuk developer' } },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]
