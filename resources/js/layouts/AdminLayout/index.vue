<script setup>
// DBD §10.7 — pembeda panel pemilik: label "Panel pemilik" + menu mengikuti izin staf (perm).
// Desain sama dengan app shell beranda (WorkspaceLayout): sidebar berkelompok, pil aktif solid, lipat ke ikon.
// Lebar: ponsel <768 = bilah bawah + lembar "Menu"; tablet 768–1279 = sidebar ikon (76px); ≥1280 = sidebar penuh (248px).
// Hanya <main> yang scroll; sidebar diam. Topbar (lokasi · cari · lonceng · peringatan) tampil mulai 768px.
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import {
  Activity, BadgeCheck, BadgePercent, Bell, Boxes, Building2, ChevronRight, ChevronsUpDown, CircleAlert, CircleCheck, CircleUser, ClipboardList, Cpu, CreditCard, Gem, Globe, HeartPulse, House, LogOut, Mail, Megaphone, Moon, Package, PanelLeft, Search, ShieldCheck, ShoppingCart, Stamp, User, Users, Wallet,
} from 'lucide-vue-next'
import BrandLogo from '@/components/common/BrandLogo/index.vue'
import InstallApp from '@/components/common/InstallApp/index.vue'
import MobileNav from '@/components/common/MobileNav/index.vue'
import PullIndicator from '@/components/common/PullIndicator/index.vue'
import { useAuthStore } from '@/stores/auth'
import { useTheme } from '@/composables/useTheme'
import { usePullToRefresh } from '@/composables/usePullToRefresh'
import { useUptimeStore } from '@/stores/uptime'

const route = useRoute()
const auth = useAuthStore()
const { toggle } = useTheme()
const mainEl = ref(null)

// tarik ke bawah (ponsel/PWA) → halaman aktif dipasang ulang sehingga datanya dimuat ulang
const refreshKey = ref(0)
const { pull, refreshing, threshold } = usePullToRefresh(mainEl, async () => {
  refreshKey.value++
  await new Promise((r) => setTimeout(r, 600))
})

// gangguan uptime realtime di halaman panel mana pun (toast + notifikasi browser)
const uptime = useUptimeStore()
watch(() => auth.can('dashboard.ops'), (ok) => ok && uptime.start(), { immediate: true })
onBeforeUnmount(() => uptime.stop())

const groups = [
  { label: 'Operasional', items: [
    { key: 'dashboard', label: 'Dashboard', icon: House, to: '/', perm: 'dashboard.ops' },
    { key: 'tenants', label: 'Workspace', icon: Building2, to: '/tenants', perm: 'tenants.view' },
    { key: 'domains', label: 'Domain kustom', icon: Globe, to: '/domains', perm: 'tenants.view' },
    { key: 'users', label: 'Pengguna', icon: Users, to: '/users', perm: 'users.view' },
    { key: 'cards', label: 'Kartu', icon: CreditCard, to: '/cards', perm: 'cards.view' },
    { key: 'field', label: 'Jual kartu', icon: BadgeCheck, to: '/aktivasi-kartu', perm: 'cards.manage' },
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
    { key: 'email-blasts', label: 'Email blast', icon: Mail, to: '/email-blasts', perm: 'comms.manage' },
  ] },
  { label: 'Sistem', items: [
    { key: 'staff', label: 'Staf & peran', icon: ShieldCheck, to: '/staff', perm: 'staff.manage' },
    { key: 'audit', label: 'Audit log', icon: ClipboardList, to: '/audit', perm: 'audit.view' },
    { key: 'system', label: 'Sistem', icon: Activity, to: '/system', perm: 'dashboard.ops' },
    { key: 'uptime', label: 'Uptime', icon: HeartPulse, to: '/uptime', perm: 'dashboard.ops' },
  ] },
]
const nav = computed(() => groups.map((g) => ({ ...g, items: g.items.filter((i) => auth.can(i.perm)) })).filter((g) => g.items.length))
// tab ponsel: 3 menu terpenting yang diizinkan peran staf ini (urutan prioritas), sisanya di "Menu"
const PRIMARY = ['dashboard', 'field', 'encode', 'orders', 'cards', 'qc', 'finance', 'tenants', 'users']
const SHORT = { dashboard: 'Dashboard', field: 'Jual', encode: 'Encode', orders: 'Pesanan', cards: 'Kartu', qc: 'QC', finance: 'Keuangan', tenants: 'Workspace', users: 'Pengguna' }
const tabs = computed(() => {
  const all = nav.value.flatMap((g) => g.items)
  return PRIMARY.map((k) => all.find((i) => i.key === k)).filter(Boolean).slice(0, 3).map((i) => ({ ...i, label: SHORT[i.key] ?? i.label }))
})
const active = computed(() => route.meta.nav)
// kembali ke atas saat pindah halaman (scroll ada di <main>); #jangkar digulir di dalam <main>
watch(() => route.path + route.hash, (key) => {
  const m = mainEl.value
  if (!m) return
  const hash = key.slice(key.indexOf('#') + 1)
  const el = key.includes('#') && hash ? document.getElementById(hash) : null
  if (el && m.contains(el)) m.scrollTo({ top: m.scrollTop + el.getBoundingClientRect().top - m.getBoundingClientRect().top - 24 })
  else m.scrollTo({ top: 0 })
}, { flush: 'post' })

// sidebar dilipat ke ikon (xl) — pilihan per perangkat, disimpan di localStorage bila tersedia
const COLLAPSE_KEY = 'tapcard.panel.sidebar.collapsed'
const readCollapsed = () => {
  try { return localStorage.getItem(COLLAPSE_KEY) === '1' } catch { return false }
}
const collapsed = ref(readCollapsed())
const toggleCollapsed = () => {
  collapsed.value = !collapsed.value
  try { localStorage.setItem(COLLAPSE_KEY, collapsed.value ? '1' : '0') } catch { /* mode privat: tetap jalan */ }
}
// lebar kolom: md = ikon (76px); xl = penuh (248px) kecuali dilipat
const shellCls = computed(() => (collapsed.value ? 'md:grid-cols-[76px_minmax(0,1fr)]' : 'md:grid-cols-[76px_minmax(0,1fr)] xl:grid-cols-[248px_minmax(0,1fr)]'))
const labelCls = computed(() => (collapsed.value ? 'hidden' : 'hidden xl:inline'))
const groupLabelCls = computed(() => (collapsed.value ? 'hidden' : 'hidden xl:block'))

const initial = computed(() => (auth.me?.name || auth.me?.email || '?')[0]?.toUpperCase())
const menu = ref(false)
const menuEl = ref(null)
const closeMenu = (e) => { if (menu.value && !menuEl.value?.contains(e.target)) menu.value = false }
watch(menu, (v) => (v ? document.addEventListener('pointerdown', closeMenu) : document.removeEventListener('pointerdown', closeMenu)))
onBeforeUnmount(() => document.removeEventListener('pointerdown', closeMenu))
watch(() => route.fullPath, () => (menu.value = false))

// pil aktif: latar solid surface-2 (tanpa gradien/bayangan)
const link = 'group flex min-h-10 cursor-pointer items-center gap-3 rounded-md px-3 text-[14px] font-medium text-muted no-underline hover:bg-surface-2 hover:text-fg aria-[current=page]:bg-surface-2 aria-[current=page]:text-fg'
</script>

<template>
  <!-- app shell: tinggi dikunci setinggi layar; hanya <main> yang scroll, sidebar diam -->
  <div :class="['grid h-dvh grid-rows-[minmax(0,1fr)] print:block print:h-auto', shellCls]">
    <aside class="hidden min-h-0 flex-col gap-5 overflow-y-auto overscroll-contain bg-surface px-3 py-5 scrollbar-none print:!hidden border-r border-line md:flex no-print" aria-label="Navigasi panel pemilik">
      <div :class="['flex flex-col items-center gap-3', collapsed ? '' : 'xl:flex-row xl:items-center xl:justify-between']">
        <RouterLink to="/" class="min-w-0 px-1 no-underline" aria-label="Dashboard">
          <span :class="collapsed ? '' : 'xl:hidden'"><BrandLogo :wordmark="false" /></span>
          <span v-if="!collapsed" class="hidden xl:grid gap-1"><BrandLogo /><span class="label !text-info !text-[10px]">Panel pemilik</span></span>
        </RouterLink>
        <button type="button" class="hidden size-9 cursor-pointer place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-fg xl:grid" :aria-label="collapsed ? 'Buka sidebar' : 'Lipat sidebar'" :aria-expanded="!collapsed" @click="toggleCollapsed">
          <PanelLeft class="size-5" aria-hidden="true" />
        </button>
      </div>

      <nav class="grid gap-4">
        <div v-for="(g, gi) in nav" :key="g.label" class="grid gap-0.5">
          <div v-if="gi > 0" :class="['mx-3 mb-2 border-t border-line', collapsed ? '' : 'xl:hidden']" aria-hidden="true"></div>
          <p :class="['label mb-1 px-3 !text-[10px]', groupLabelCls]">{{ g.label }}</p>
          <RouterLink v-for="item in g.items" :key="item.key" :to="item.to" :title="item.label" :aria-label="item.label" :aria-current="active === item.key ? 'page' : undefined" :class="[link, 'justify-center', collapsed ? '' : 'xl:justify-start']">
            <component :is="item.icon" class="size-[18px] flex-none group-aria-[current=page]:text-accent-text" aria-hidden="true" />
            <span :class="['truncate', labelCls]">{{ item.label }}</span>
          </RouterLink>
        </div>
      </nav>

      <!-- akun: inisial saja di tablet/terlipat (ke Akun); kartu lengkap + menu di xl -->
      <div class="mt-auto">
        <RouterLink to="/account" :class="['mx-auto grid size-9 place-items-center rounded-md bg-surface-2 font-display text-sm font-semibold no-underline text-fg', collapsed ? '' : 'xl:hidden']" aria-label="Akun & password">
          {{ initial }}
        </RouterLink>
        <div ref="menuEl" :class="['relative', collapsed ? 'hidden' : 'hidden xl:block']">
          <div v-if="menu" class="absolute inset-x-0 bottom-full mb-2 grid gap-0.5 rounded-md border border-line-strong bg-surface p-1.5" role="menu">
            <RouterLink to="/account" class="flex min-h-9 cursor-pointer items-center gap-3 rounded-sm px-3 text-sm text-fg no-underline hover:bg-surface-2" role="menuitem"><User class="size-4" /> Akun & password</RouterLink>
            <button type="button" class="flex min-h-9 cursor-pointer items-center gap-3 rounded-sm px-3 text-sm text-fg hover:bg-surface-2" role="menuitem" @click="toggle"><Moon class="size-4" /> Ganti tema</button>
            <InstallApp link-class="flex min-h-9 w-full items-center gap-3 rounded-sm px-3 text-sm text-fg hover:bg-surface-2 [&_svg]:size-4" />
            <button type="button" class="flex min-h-9 cursor-pointer items-center gap-3 rounded-sm px-3 text-sm text-danger hover:bg-surface-2" role="menuitem" @click="auth.askLogout()"><LogOut class="size-4" /> Keluar</button>
          </div>
          <button type="button" class="flex w-full cursor-pointer items-center gap-3 rounded-md p-2 text-left hover:bg-surface-2" :aria-expanded="menu" aria-haspopup="menu" @click="menu = !menu">
            <span class="grid size-9 flex-none place-items-center rounded-md bg-surface-2 font-display text-sm font-semibold" aria-hidden="true">{{ initial }}</span>
            <span class="grid min-w-0 flex-1">
              <span class="truncate text-sm font-semibold">{{ auth.me?.name || 'Akun' }}</span>
              <span class="truncate text-xs text-muted">{{ auth.me?.email || auth.me?.role || '' }}</span>
            </span>
            <ChevronsUpDown class="size-4 flex-none text-muted" aria-hidden="true" />
          </button>
        </div>
      </div>
    </aside>

    <PullIndicator :pull="pull" :refreshing="refreshing" :threshold="threshold" />
    <!-- kolom kanan: topbar diam di atas (tinggi tetap), hanya <main> yang scroll -->
    <div class="flex min-h-0 min-w-0 flex-col">
      <!-- ponsel: merek + peringatan · tablet & desktop: lokasi (panel › halaman), cari, lonceng, peringatan -->
      <header class="flex h-14 shrink-0 items-center gap-3 px-4 print:hidden md:h-16 md:px-6 lg:px-8">
        <div class="flex w-full items-center justify-between md:hidden">
          <RouterLink to="/" class="no-underline"><BrandLogo /></RouterLink>
          <RouterLink to="/approvals" class="grid size-10 flex-none place-items-center rounded-md text-muted no-underline hover:bg-surface-2 hover:text-fg" aria-label="Perlu tindak lanjut"><CircleAlert class="size-5" aria-hidden="true" /></RouterLink>
        </div>
        <div class="hidden min-w-0 flex-1 items-center gap-3 md:flex">
          <nav aria-label="Lokasi" class="min-w-0 flex-1">
            <p class="flex min-w-0 items-center gap-1.5 text-sm text-muted">
              <span class="truncate">Panel pemilik</span>
              <ChevronRight class="size-4 flex-none" aria-hidden="true" />
              <span class="truncate font-medium text-fg" aria-current="page">{{ route.meta.title }}</span>
            </p>
          </nav>
          <!-- belum ada pencarian global di panel: tautan ke daftar workspace (tempat mencari tenant) -->
          <RouterLink to="/tenants" class="hidden h-10 w-64 items-center gap-2 rounded-md border border-line bg-surface px-3 text-sm text-muted no-underline hover:text-fg xl:flex" aria-label="Cari workspace">
            <Search class="size-4 flex-none" aria-hidden="true" /><span class="min-w-0 flex-1 truncate">Cari workspace…</span>
          </RouterLink>
          <!-- panel belum punya umpan notifikasi: lonceng menuju gangguan uptime -->
          <RouterLink to="/uptime" class="grid size-10 flex-none place-items-center rounded-md text-muted no-underline hover:bg-surface-2 hover:text-fg" aria-label="Notifikasi gangguan uptime">
            <Bell class="size-5" aria-hidden="true" />
          </RouterLink>
          <RouterLink to="/approvals" class="grid size-10 flex-none place-items-center rounded-md text-muted no-underline hover:bg-surface-2 hover:text-fg" aria-label="Perlu tindak lanjut">
            <CircleAlert class="size-5" aria-hidden="true" />
          </RouterLink>
        </div>
      </header>

      <!-- satu-satunya area yang scroll; ponsel: padding bawah = ruang MobileNav + safe-area -->
      <main ref="mainEl" class="relative min-h-0 min-w-0 flex-1 overflow-y-auto overscroll-contain px-4 pt-6 pb-[calc(88px+env(safe-area-inset-bottom))] print:overflow-visible print:p-0 md:px-6 md:pb-10 lg:px-8 lg:pt-8 lg:pb-12">
        <div class="grid w-full gap-6">
          <RouterView v-slot="{ Component }"><component :is="Component" :key="refreshKey" /></RouterView>
        </div>
      </main>
    </div>

    <MobileNav
      bp="md"
      :tabs="tabs" :groups="nav" :active="active"
      :title="auth.me?.name || auth.me?.email || 'Staf'" :subtitle="`Panel pemilik · ${auth.me?.role ?? ''}`" :initial="initial"
    >
      <template #actions>
        <RouterLink to="/account" class="flex min-h-11 items-center gap-3 rounded-sm px-3 text-sm text-fg no-underline hover:bg-surface-2"><CircleUser class="size-5" /> Akun & password</RouterLink>
        <InstallApp link-class="flex min-h-11 w-full items-center gap-3 rounded-sm px-3 text-sm text-fg hover:bg-surface-2 [&_svg]:size-5" />
        <button type="button" class="flex min-h-11 cursor-pointer items-center gap-3 rounded-sm px-3 text-sm text-fg hover:bg-surface-2" @click="toggle"><Moon class="size-5" /> Ganti tema</button>
        <button type="button" class="flex min-h-11 cursor-pointer items-center gap-3 rounded-sm px-3 text-sm text-danger hover:bg-surface-2" @click="auth.askLogout()"><LogOut class="size-5" /> Keluar</button>
      </template>
    </MobileNav>
  </div>
</template>
