// panel.{base} — panel pemilik & produksi (PRD §10.9, layar M-01…M-07)
import AdminLayout from '@/layouts/AdminLayout/index.vue'

const page = (name) => () => import(`@/pages/admin/${name}/index.vue`)

export default [
  { path: '/login', name: 'login', component: () => import('@/pages/auth/Login/index.vue'), meta: { guest: true, title: 'Masuk panel pemilik' } },
  {
    path: '/',
    component: AdminLayout,
    meta: { auth: true },
    children: [
      { path: '', name: 'dashboard', component: page('Dashboard'), meta: { title: 'Dashboard', nav: 'dashboard', perm: 'dashboard.ops' } },
      { path: 'tenants', name: 'tenants', component: page('Tenants'), meta: { title: 'Workspace', nav: 'tenants', perm: 'tenants.view' } },
      { path: 'tenants/:id', name: 'tenant-detail', component: page('TenantDetail'), meta: { title: 'Workspace', nav: 'tenants', perm: 'tenants.view' } },
      { path: 'users', name: 'users', component: page('Users'), meta: { title: 'Pengguna', nav: 'users', perm: 'users.view' } },
      { path: 'users/:id', name: 'user-detail', component: page('UserDetail'), meta: { title: 'Pengguna', nav: 'users', perm: 'users.view' } },
      { path: 'cards', name: 'cards', component: page('Cards'), meta: { title: 'Kartu', nav: 'cards', perm: 'cards.view' } },
      { path: 'aktivasi-kartu', name: 'card-activation', component: page('FieldActivation'), meta: { title: 'Aktivasi kartu', nav: 'field', perm: 'cards.manage' } },
      { path: 'field-activation', redirect: '/aktivasi-kartu' },
      { path: 'cards/:token', name: 'card-detail', component: page('CardDetail'), meta: { title: 'Kartu', nav: 'cards', perm: 'cards.view' } },
      { path: 'batches', name: 'batches', component: page('Batches'), meta: { title: 'Produksi', nav: 'batches', perm: 'production.manage' } },
      { path: 'batches/:id', name: 'batch-detail', component: page('BatchDetail'), meta: { title: 'Batch', nav: 'batches', perm: 'production.manage' } },
      { path: 'batches/:id/print', name: 'batch-print', component: page('BatchPrint'), meta: { title: 'Lembar QR', nav: 'batches', perm: 'production.manage' } },
      { path: 'encode-station', name: 'encode', component: page('EncodeStation'), meta: { title: 'Stasiun encode', nav: 'encode', perm: 'production.manage' } },
      { path: 'qc', name: 'qc', component: page('QcStation'), meta: { title: 'QC', nav: 'qc', perm: 'production.manage' } },
      { path: 'orders', name: 'orders', component: page('Orders'), meta: { title: 'Pesanan', nav: 'orders', perm: 'orders.view' } },
      { path: 'orders/:id', name: 'order-detail', component: page('OrderDetail'), meta: { title: 'Pesanan', nav: 'orders', perm: 'orders.view' } },
      { path: 'domains', name: 'domains', component: page('Domains'), meta: { title: 'Domain kustom', nav: 'domains', perm: 'tenants.view' } },
      { path: 'plans', name: 'plans', component: page('Plans'), meta: { title: 'Paket & harga', nav: 'plans', perm: 'plans.manage' } },
      { path: 'finance', name: 'finance', component: page('Finance'), meta: { title: 'Keuangan', nav: 'finance', perm: 'finance.view' } },
      { path: 'finance/invoices/:id', name: 'finance-invoice', component: page('FinanceInvoice'), meta: { title: 'Invoice', nav: 'finance', perm: 'finance.view' } },
      { path: 'approvals', name: 'approvals', component: page('Approvals'), meta: { title: 'Persetujuan & tinjauan', nav: 'approvals', perm: 'finance.view' } },
      { path: 'vouchers', name: 'vouchers', component: page('Vouchers'), meta: { title: 'Voucher', nav: 'vouchers', perm: 'vouchers.manage' } },
      { path: 'catalog', name: 'catalog', component: page('Catalog'), meta: { title: 'Katalog', nav: 'catalog', perm: 'catalog.manage' } },
      { path: 'announcements', name: 'announcements', component: page('Announcements'), meta: { title: 'Pengumuman', nav: 'announcements', perm: 'comms.manage' } },
      { path: 'staff', name: 'staff', component: page('Staff'), meta: { title: 'Staf & peran', nav: 'staff', perm: 'staff.manage' } },
      { path: 'audit', name: 'audit', component: page('Audit'), meta: { title: 'Audit log', nav: 'audit', perm: 'audit.view' } },
      { path: 'account', name: 'account', component: page('Account'), meta: { title: 'Akun', nav: 'account' } },
      { path: 'system', name: 'system', component: page('System'), meta: { title: 'Sistem', nav: 'system', perm: 'dashboard.ops' } },
    ],
  },
  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/pages/public/NotFound/index.vue') },
]
