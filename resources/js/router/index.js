import { createRouter, createWebHistory } from 'vue-router'
import { context } from '@/config'
import { setUnauthenticatedHandler } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import publicRoutes from './routes/public'
import workspaceRoutes from './routes/workspace'
import adminRoutes from './routes/admin'
import marketingRoutes from './routes/marketing'
import statusRoutes from './routes/status'
import webhookRoutes from './routes/webhooks'

// TRD §4.6 — satu SPA, kumpulan rute dipilih dari host.
const routesByContext = { public: publicRoutes, workspace: workspaceRoutes, admin: adminRoutes, marketing: marketingRoutes, status: statusRoutes, webhooks: webhookRoutes }

export const router = createRouter({
  history: createWebHistory(),
  routes: routesByContext[context],
  // tautan jangkar (#bagian) harus mendarat di bawah header sticky (64 px + ruang baca), bukan di atas halaman
  scrollBehavior: (to, from, saved) => saved ?? (to.hash ? { el: to.hash, top: 80 } : { top: 0 }),
})

router.beforeEach(async (to) => {
  if (context !== 'workspace' && context !== 'admin') return true
  const auth = useAuthStore()
  await auth.load()

  if (to.matched.some((r) => r.meta.auth) && !auth.isLoggedIn) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  if (to.meta.guest && auth.isLoggedIn) {
    return { name: 'dashboard' }
  }
  // masa aktif workspace perusahaan habis: hanya halaman tagihan yang terbuka
  if (context === 'workspace' && auth.workspace?.expired && to.matched.some((r) => r.meta.auth) && !to.path.startsWith('/billing')) {
    return { path: '/billing' }
  }
  if (context === 'admin' && to.meta.perm && !auth.can(to.meta.perm)) {
    return to.name === 'dashboard' ? true : { name: 'dashboard' }
  }
  return true
})

router.afterEach((to) => {
  if (to.meta.title === null) return // judul diatur halaman / server (mis. profil publik)
  const suffix = context === 'admin' ? ' · TapCard Panel' : ' · TapCard'
  document.title = to.meta.title ? (to.meta.title.startsWith('TapCard') ? to.meta.title : to.meta.title + suffix) : 'TapCard'
})

// Sesi habis di tengah jalan → kembali ke login dengan tujuan semula.
setUnauthenticatedHandler(() => {
  const auth = useAuthStore()
  if (!auth.isLoggedIn) return
  auth.clear()
  const current = router.currentRoute.value
  if (current.name !== 'login') router.push({ name: 'login', query: { redirect: current.fullPath } })
})
