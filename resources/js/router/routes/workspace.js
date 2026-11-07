// app.{base} & {slug}.{base} — workspace (AFD §3, layar U-01…U-16)
import WorkspaceLayout from '@/layouts/WorkspaceLayout/index.vue'

const page = (name) => () => import(`@/pages/workspace/${name}/index.vue`)

export default [
  { path: '/login', name: 'login', component: () => import('@/pages/auth/Login/index.vue'), meta: { guest: true, title: 'Masuk' } },
  { path: '/login/kartu', name: 'login-card', component: () => import('@/pages/auth/LoginCard/index.vue'), meta: { guest: true, title: 'Masuk dengan kartu' } },
  // tautan lama: daftar = masuk dengan Google (akun dibuat otomatis); tidak ada password untuk direset
  { path: '/register', redirect: (to) => ({ name: 'login', query: to.query }) },
  { path: '/forgot-password', redirect: { name: 'login' } },
  {
    path: '/',
    component: WorkspaceLayout,
    meta: { auth: true },
    children: [
      { path: '', redirect: { name: 'dashboard' } },
      { path: 'dashboard', name: 'dashboard', component: page('Dashboard'), meta: { title: 'Beranda', nav: 'dashboard' } },
      { path: 'cards', name: 'cards', component: page('Cards'), meta: { title: 'Kartu', nav: 'cards' } },
      { path: 'cards/:token', name: 'card-detail', component: page('CardDetail'), meta: { title: 'Kartu', nav: 'cards' } },
      { path: 'cards/:token/bio', name: 'card-bio', component: page('BioEditor'), meta: { title: 'Bio link', nav: 'cards' } },
      { path: 'share', name: 'share-shortcut', component: page('ShareShortcut'), meta: { title: 'Bagikan kartu', nav: 'cards' } },
      { path: 'cards/:token/share', name: 'card-share', component: page('CardShare'), meta: { title: 'Bagikan kartu', nav: 'cards' } },
      { path: 'cards/:token/qr', name: 'card-qr', component: page('CardQr'), meta: { title: 'QR aman', nav: 'cards' } },
      { path: 'cards/:token/setup', name: 'card-setup', component: page('CardSetup'), meta: { title: 'Siapkan kartu', nav: 'cards' } },
      { path: 'activate/:token', name: 'activate', component: page('Activate'), meta: { title: 'Aktifkan kartu', nav: 'cards' } },
      // bio link kini per kartu (/cards/:token/bio); alamat lama diarahkan ke daftar kartu
      { path: 'profiles/:rest(.*)*', redirect: { name: 'cards' } },
      { path: 'connections', name: 'connections', component: page('Connections'), meta: { title: 'Koneksi', nav: 'connections' } },
      { path: 'connections/dashboard', name: 'lead-dashboard', component: page('LeadDashboard'), meta: { title: 'Dashboard lead', nav: 'lead-dashboard' } },
      { path: 'connections/import', name: 'lead-import', component: page('LeadImport'), meta: { title: 'Impor CSV', nav: 'connections' } },
      { path: 'follow-up', name: 'follow-ups', component: page('FollowUps'), meta: { title: 'Follow-up', nav: 'follow-up' } },
      { path: 'scan', name: 'scan', component: page('Scan'), meta: { title: 'Scan kartu', nav: 'connections' } },
      { path: 'events', name: 'events', component: page('Events'), meta: { title: 'Event', nav: 'events' } },
      { path: 'events/:id', name: 'event-detail', component: page('EventDetail'), meta: { title: 'Event', nav: 'events' } },
      { path: 'connections/:id', name: 'connection-detail', component: page('ConnectionDetail'), meta: { title: 'Koneksi', nav: 'connections' } },
      { path: 'designs', name: 'designs', component: page('Designs'), meta: { title: 'Desain', nav: 'designs' } },
      // editor tampil layar penuh (tanpa sidebar) — lihat WorkspaceLayout
      { path: 'designs/:id', name: 'studio', component: page('Studio'), meta: { title: 'Design Studio', nav: 'designs', fullscreen: true } },
      { path: 'lanyards/:id', name: 'lanyard-studio', component: page('LanyardStudio'), meta: { title: 'Editor lanyard', nav: 'designs', fullscreen: true } },
      { path: 'merch/:id', name: 'merch-studio', component: page('MerchStudio'), meta: { title: 'Editor merchandise', nav: 'designs', fullscreen: true } },
      { path: 'analytics', name: 'analytics', component: page('Analytics'), meta: { title: 'Analitik', nav: 'analytics' } },
      { path: 'orders', name: 'orders', component: page('Orders'), meta: { title: 'Pesanan', nav: 'orders' } },
      { path: 'orders/new', name: 'order-new', component: page('OrderNew'), meta: { title: 'Keranjang & checkout', nav: 'orders' } },
      { path: 'orders/:id', name: 'order-detail', component: page('OrderDetail'), meta: { title: 'Pesanan', nav: 'orders' } },
      { path: 'billing', name: 'billing', component: page('Billing'), meta: { title: 'Paket & tagihan', nav: 'billing' } },
      { path: 'billing/invoices/:id', name: 'billing-invoice', component: page('BillingInvoice'), meta: { title: 'Invoice', nav: 'billing' } },
      { path: 'billing/pay/:id', name: 'billing-pay', component: page('BillingPay'), meta: { title: 'Pembayaran', nav: 'billing', fullscreen: true } },
      { path: 'addons', name: 'addons', component: page('Addons'), meta: { title: 'Add-on', nav: 'addons' } },
      { path: 'settings', name: 'settings', component: page('Settings'), meta: { title: 'Pengaturan', nav: 'settings' } },
    ],
  },
  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/pages/public/NotFound/index.vue'), meta: { title: 'Tidak ditemukan' } },
]
