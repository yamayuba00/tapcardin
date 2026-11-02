<script setup>
// DBD §10.7 — pembeda panel pemilik: bilah atas 4 px --info + label "PANEL PEMILIK". Menu mengikuti izin staf.
// App shell: tinggi layar penuh, sidebar & konten masing-masing scroll sendiri (sidebar tidak ikut tergulung).
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import {
  Activity, BadgeCheck, BadgePercent, Boxes, Building2, CircleCheck, CircleUser, ClipboardList, Cpu, CreditCard, Gem, Globe, House, LogOut, Megaphone, Menu, Moon, Package, ShieldCheck, ShoppingCart, Stamp, Users, Wallet,
} from 'lucide-vue-next'
import BrandLogo from '@/components/common/BrandLogo/index.vue'
import { useAuthStore } from '@/stores/auth'
import { useTheme } from '@/composables/useTheme'

const route = useRoute()
const auth = useAuthStore()
const { toggle } = useTheme()
const menuOpen = ref(false)
const mainEl = ref(null)

const groups = [
  { label: 'Operasional', items: [
    { key: 'dashboard', label: 'Dashboard', icon: House, to: '/', perm: 'dashboard.ops' },
    { key: 'tenants', label: 'Workspace', icon: Building2, to: '/tenants', perm: 'tenants.view' },
    { key: 'domains', label: 'Domain kustom', icon: Globe, to: '/domains', perm: 'tenants.view' },
    { key: 'users', label: 'Pengguna', icon: Users, to: '/users', perm: 'users.view' },
    { key: 'cards', label: 'Kartu', icon: CreditCard, to: '/cards', perm: 'cards.view' },
    { key: 'field', label: 'Aktivasi di tempat', icon: BadgeCheck, to: '/field-activation', perm: 'cards.manage' },
  ] },
  { label: 'Produksi', items: [
    { key: 'orders', label: 'Pesanan', icon: ShoppingCart, to: '/orders', perm: 'orders.view' },
    { key: 'batches', label: 'Batch', icon: Boxes, to: '/batches', perm: 'production.manage' },
    { key: 'encode', label: 'Stasiun encode', icon: Cpu, to: '/encode-station', perm: 'production.manage' },
    { key: 'qc', label: 'QC', icon: CircleCheck, to: '/qc', perm: 'production.manage' },
  ] },
  { label: 'Bisnis', items: [
    { key: 'plans', label: 'Paket & harga', icon: Gem, to: '/plans', perm: 'plans.manage' },
    { key: 'finance', label: 'Keuangan', icon: Wallet, to: '/finance', perm: 'finance.view' },
    { key: 'approvals', label: 'Persetujuan', icon: Stamp, to: '/approvals', perm: 'finance.view' },
    { key: 'vouchers', label: 'Voucher', icon: BadgePercent, to: '/vouchers', perm: 'vouchers.manage' },
    { key: 'catalog', label: 'Katalog', icon: Package, to: '/catalog', perm: 'catalog.manage' },
    { key: 'announcements', label: 'Pengumuman', icon: Megaphone, to: '/announcements', perm: 'comms.manage' },
  ] },
  { label: 'Sistem', items: [
    { key: 'staff', label: 'Staf & peran', icon: ShieldCheck, to: '/staff', perm: 'staff.manage' },
    { key: 'audit', label: 'Audit log', icon: ClipboardList, to: '/audit', perm: 'audit.view' },
    { key: 'system', label: 'Sistem', icon: Activity, to: '/system', perm: 'dashboard.ops' },
  ] },
]
const nav = computed(() => groups.map((g) => ({ ...g, items: g.items.filter((i) => auth.can(i.perm)) })).filter((g) => g.items.length))
watch(() => route.fullPath, () => (menuOpen.value = false))
watch(() => route.path, () => mainEl.value?.scrollTo({ top: 0 }))

const link = 'group flex min-h-10 items-center gap-3 rounded-lg px-3 text-[15px] font-medium text-muted no-underline hover:bg-surface-2 hover:text-fg aria-[current=page]:bg-surface-2 aria-[current=page]:text-fg'
</script>

<template>
  <div class="grid h-dvh grid-rows-[4px_minmax(0,1fr)] print:block print:h-auto">
    <div class="bg-info no-print"></div>
    <div class="grid min-h-0 lg:grid-cols-[248px_minmax(0,1fr)] print:block">
      <aside class="hidden min-h-0 flex-col gap-5 overflow-y-auto overscroll-contain border-r border-line px-4 py-6 scrollbar-none lg:flex no-print" aria-label="Navigasi panel pemilik">
        <div class="grid gap-2">
          <RouterLink to="/" class="no-underline"><BrandLogo /></RouterLink>
          <span class="label !text-info">Panel pemilik</span>
        </div>
        <nav class="grid gap-4">
          <div v-for="g in nav" :key="g.label" class="grid gap-0.5">
            <span class="label px-3 pb-1">{{ g.label }}</span>
            <RouterLink v-for="item in g.items" :key="item.key" :to="item.to" :aria-current="route.meta.nav === item.key ? 'page' : undefined" :class="link">
              <component :is="item.icon" class="size-5 group-aria-[current=page]:text-accent-text" aria-hidden="true" /> {{ item.label }}
            </RouterLink>
          </div>
        </nav>
        <div class="mt-auto grid gap-1">
          <p class="truncate px-3 text-sm text-muted">{{ auth.me?.email }}<br /><span class="label">{{ auth.me?.role }}</span></p>
          <RouterLink to="/account" :aria-current="route.meta.nav === 'account' ? 'page' : undefined" :class="link"><CircleUser class="size-5" aria-hidden="true" /> Akun & password</RouterLink>
          <button class="cursor-pointer" type="button" :class="link" @click="toggle"><Moon class="size-5" aria-hidden="true" /> Tema</button>
          <button class="cursor-pointer" type="button" :class="link" @click="auth.logout()"><LogOut class="size-5" aria-hidden="true" /> Keluar</button>
        </div>
      </aside>

      <main ref="mainEl" class="min-h-0 min-w-0 overflow-y-auto overscroll-contain px-4 py-6 print:overflow-visible print:p-0 lg:px-8 lg:py-8">
        <div class="grid w-full gap-6">
          <div class="lg:hidden no-print">
            <!-- Keluar selalu terlihat di ponsel (tidak tersembunyi di dalam menu) -->
            <div class="flex items-center justify-between gap-2">
              <button type="button" class="cursor-pointer inline-flex min-h-11 items-center gap-2 rounded-md border border-line-strong px-4 font-semibold" :aria-expanded="menuOpen" @click="menuOpen = !menuOpen">
                <Menu class="size-5" aria-hidden="true" /> Menu
              </button>
              <button type="button" class="cursor-pointer inline-flex min-h-11 items-center gap-2 rounded-md border border-line-strong px-4 font-semibold text-danger" @click="auth.logout()">
                <LogOut class="size-5" aria-hidden="true" /> Keluar
              </button>
            </div>
            <nav v-if="menuOpen" class="mt-3 grid gap-3">
              <div v-for="g in nav" :key="g.label" class="grid gap-0.5">
                <span class="label px-3">{{ g.label }}</span>
                <RouterLink v-for="item in g.items" :key="item.key" :to="item.to" :class="link"><component :is="item.icon" class="size-5" /> {{ item.label }}</RouterLink>
              </div>
              <RouterLink to="/account" :class="link"><CircleUser class="size-5" /> Akun & password</RouterLink>
              <button class="cursor-pointer" type="button" :class="link" @click="auth.logout()"><LogOut class="size-5" /> Keluar</button>
            </nav>
          </div>
          <RouterView />
        </div>
      </main>
    </div>
  </div>
</template>
