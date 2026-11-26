<script setup>
// AFD §8 — desktop: sidebar berkelompok (Kartu · Lead · Lainnya) setinggi layar tanpa ikut scroll;
// ponsel: bottom bar 4 item (Beranda · Koneksi · Kartu · Menu). "Menu" membuka lembar berisi semua menu sidebar
// + akun (tema, pasang aplikasi, keluar), jadi tidak ada halaman yang hanya bisa dibuka dari desktop.
// Desain dashboard (referensi): tablet 768–1279 = sidebar ikon saja (tanpa bottom bar); ≥1280 = sidebar penuh
// yang bisa dilipat ke ikon. Topbar (lokasi · cari · lonceng · tindak lanjut) tampil mulai 768px.
// Rute dengan `meta.fullscreen` (editor kartu & lanyard) tampil tanpa sidebar & padding.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import {
  BellRing, Blocks, Building2, CalendarDays, ChartColumn, ChevronRight, ChevronsUpDown, CircleAlert, ClipboardList, CreditCard, Gem, House, LayoutDashboard, LogOut, Moon, Package, PanelLeft, Palette, Search, Settings, User, Users, Webhook,
} from 'lucide-vue-next'
import NotificationBell from '@/components/common/NotificationBell/index.vue'
import BrandLogo from '@/components/common/BrandLogo/index.vue'
import InstallApp from '@/components/common/InstallApp/index.vue'
import MobileNav from '@/components/common/MobileNav/index.vue'
import PullIndicator from '@/components/common/PullIndicator/index.vue'
import { useAuthStore } from '@/stores/auth'
import { useNotificationStore } from '@/stores/notifications'
import { useTheme } from '@/composables/useTheme'
import { usePullToRefresh } from '@/composables/usePullToRefresh'

const route = useRoute()
const auth = useAuthStore()
const notifications = useNotificationStore()
const { toggle } = useTheme()

const canWebhook = computed(() => auth.can('integrations.manage') || auth.can('workspace.settings'))
const groups = computed(() => [
  { label: null, items: [{ key: 'dashboard', label: 'Beranda', icon: House, to: '/dashboard' }] },
  // workspace perusahaan: kelola karyawan & rekap lead (owner/admin), kartu karyawan (juga Manager untuk timnya), Webhook
  ...(auth.workspace && auth.workspace.type !== 'personal' && (auth.can('members.invite') || auth.can('cards.assign.*') || auth.can('cards.view.all') || canWebhook.value)
    ? [{ label: 'Perusahaan', items: [
      (auth.can('members.invite') || auth.can('cards.assign.*')) && { key: 'members', label: auth.can('members.invite') ? 'Karyawan' : 'Kartu tim', icon: Building2, to: '/members' },
      auth.can('cards.view.all') && { key: 'card-report', label: 'Laporan kartu', icon: ClipboardList, to: '/reports/cards' },
      canWebhook.value && { key: 'webhooks', label: 'Webhook', icon: Webhook, to: '/webhooks' },
    ].filter(Boolean) }] : []),
  { label: 'Kartu', items: [
    { key: 'cards', label: 'Kartu saya', icon: CreditCard, to: '/cards' },
    { key: 'designs', label: 'Desain & merchandise', icon: Palette, to: '/designs' },
    { key: 'orders', label: 'Pesanan', icon: Package, to: '/orders' },
  ] },
  { label: auth.contactLabel, items: [
    { key: 'connections', label: `Daftar ${auth.contactLabel.toLowerCase()}`, icon: Users, to: '/connections' },
    { key: 'lead-dashboard', label: `Ringkasan ${auth.contactLabel.toLowerCase()}`, icon: LayoutDashboard, to: '/connections/dashboard' },
    { key: 'follow-up', label: 'Follow-up', icon: BellRing, to: '/follow-up' },
    { key: 'events', label: 'Event', icon: CalendarDays, to: '/events' },
  ] },
  { label: 'Lainnya', items: [
    { key: 'analytics', label: 'Analitik', icon: ChartColumn, to: '/analytics' },
    { key: 'billing', label: 'Paket & tagihan', icon: Gem, to: '/billing' },
    { key: 'addons', label: 'Add-on', icon: Blocks, to: '/addons' },
    { key: 'settings', label: 'Pengaturan', icon: Settings, to: '/settings' },
  ] },
])
const active = computed(() => route.meta.nav)
// konten di-scroll di <main>, bukan window → kembalikan ke atas saat pindah halaman;
// tautan #jangkar digulir di dalam <main> (window tidak ikut bergerak)
const mainEl = ref(null)
watch(() => route.path + route.hash, (key) => {
  const m = mainEl.value
  if (!m) return
  const hash = key.slice(key.indexOf('#') + 1)
  const el = key.includes('#') && hash ? document.getElementById(hash) : null
  if (el && m.contains(el)) m.scrollTo({ top: m.scrollTop + el.getBoundingClientRect().top - m.getBoundingClientRect().top - 24 })
  else m.scrollTo({ top: 0 })
}, { flush: 'post' })

// tarik ke bawah (ponsel/PWA) → halaman aktif dipasang ulang sehingga datanya dimuat ulang
const refreshKey = ref(0)
const { pull, refreshing, threshold } = usePullToRefresh(mainEl, async () => {
  refreshKey.value++
  await new Promise((r) => setTimeout(r, 600))
})
const fullscreen = computed(() => route.meta.fullscreen === true)

// sidebar dilipat ke ikon (xl) — pilihan per perangkat, disimpan di localStorage bila tersedia
const COLLAPSE_KEY = 'tapcard.sidebar.collapsed'
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
const wsName = computed(() => (auth.workspace?.type === 'personal' ? 'Personal' : auth.workspace?.name ?? ''))

// ponsel: 3 tab utama + "Menu" (kelompok berlabel; Beranda sudah jadi tab)
const tabs = computed(() => [
  { key: 'dashboard', label: 'Beranda', icon: House, to: '/dashboard' },
  { key: 'connections', label: auth.contactLabel, icon: Users, to: '/connections' },
  { key: 'cards', label: 'Kartu', icon: CreditCard, to: '/cards' },
])
const workspaceLine = computed(() => `${auth.workspace?.type === 'personal' ? 'Personal' : auth.workspace?.name ?? ''} · ${auth.workspace?.plan_name ?? ''}`)

const menu = ref(false)
const menuEl = ref(null)
const closeMenu = (e) => { if (menu.value && !menuEl.value?.contains(e.target)) menu.value = false }
onMounted(() => {
  notifications.start()
  document.addEventListener('pointerdown', closeMenu)
})
onBeforeUnmount(() => document.removeEventListener('pointerdown', closeMenu))
// pill aktif: latar solid surface-2 (tanpa gradien/bayangan)
const link = 'group flex min-h-10 cursor-pointer items-center gap-3 rounded-md px-3 text-[14px] font-medium text-muted no-underline hover:bg-surface-2 hover:text-fg aria-[current=page]:bg-surface-2 aria-[current=page]:text-fg'
</script>

<template>
  <div v-if="fullscreen" class="min-h-dvh"><RouterView /></div>

  <!-- app shell: tinggi dikunci setinggi layar; hanya <main> yang scroll, sidebar diam -->
  <div v-else :class="['grid h-dvh grid-rows-[minmax(0,1fr)] print:block print:h-auto', shellCls]">
    <aside class="hidden min-h-0 flex-col gap-5 overflow-y-auto overscroll-contain bg-surface px-3 py-5 scrollbar-none print:!hidden border-r border-line md:flex" aria-label="Navigasi utama">
      <div :class="['flex flex-col items-center gap-3', collapsed ? '' : 'xl:flex-row xl:items-center xl:justify-between']">
        <RouterLink to="/dashboard" class="min-w-0 px-1 no-underline" aria-label="Beranda">
          <span :class="collapsed ? '' : 'xl:hidden'"><BrandLogo :wordmark="false" /></span>
          <span v-if="!collapsed" class="hidden xl:block"><BrandLogo /></span>
        </RouterLink>
        <button type="button" class="hidden size-9 cursor-pointer place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-fg xl:grid" :aria-label="collapsed ? 'Buka sidebar' : 'Lipat sidebar'" :aria-expanded="!collapsed" @click="toggleCollapsed">
          <PanelLeft class="size-5" aria-hidden="true" />
        </button>
      </div>

      <nav class="grid gap-4">
        <div v-for="(g, gi) in groups" :key="gi" class="grid gap-0.5">
          <div v-if="gi > 0" :class="['mx-3 mb-2 border-t border-line', collapsed ? '' : 'xl:hidden']" aria-hidden="true"></div>
          <p v-if="g.label" :class="['label mb-1 px-3 !text-[10px]', groupLabelCls]">{{ g.label }}</p>
          <RouterLink v-for="item in g.items" :key="item.key" :to="item.to" :title="item.label" :aria-label="item.label" :aria-current="active === item.key ? 'page' : undefined" :class="[link, 'justify-center', collapsed ? '' : 'xl:justify-start']">
            <component :is="item.icon" class="size-[18px] flex-none group-aria-[current=page]:text-accent-text" aria-hidden="true" />
            <span :class="['truncate', labelCls]">{{ item.label }}</span>
          </RouterLink>
        </div>
      </nav>

      <!-- akun: ikon saja di tablet/terlipat (ke Pengaturan); menu lengkap (tema, pasang, keluar) di xl -->
      <div class="mt-auto">
        <RouterLink to="/settings" :class="['mx-auto grid size-9 place-items-center rounded-md bg-surface-2 font-display text-sm font-semibold no-underline text-fg', collapsed ? '' : 'xl:hidden']" aria-label="Akun & pengaturan">
          {{ (auth.me?.name || auth.workspace?.name || '?')[0]?.toUpperCase() }}
        </RouterLink>
        <div ref="menuEl" :class="['relative', collapsed ? 'hidden' : 'hidden xl:block']">
          <div v-if="menu" class="absolute inset-x-0 bottom-full mb-2 grid gap-0.5 rounded-md border border-line-strong bg-surface p-1.5" role="menu">
            <RouterLink to="/settings" class="flex min-h-9 cursor-pointer items-center gap-3 rounded-sm px-3 text-sm text-fg no-underline hover:bg-surface-2" role="menuitem" @click="menu = false"><User class="size-4" /> Akun & pengaturan</RouterLink>
            <button type="button" class="flex min-h-9 cursor-pointer items-center gap-3 rounded-sm px-3 text-sm text-fg hover:bg-surface-2" role="menuitem" @click="toggle"><Moon class="size-4" /> Ganti tema</button>
            <InstallApp link-class="flex min-h-9 w-full items-center gap-3 rounded-sm px-3 text-sm text-fg hover:bg-surface-2 [&_svg]:size-4" />
            <button type="button" class="flex min-h-9 cursor-pointer items-center gap-3 rounded-sm px-3 text-sm text-danger hover:bg-surface-2" role="menuitem" @click="auth.askLogout()"><LogOut class="size-4" /> Keluar</button>
          </div>
          <button type="button" class="flex w-full cursor-pointer items-center gap-3 rounded-md p-2 text-left hover:bg-surface-2" :aria-expanded="menu" aria-haspopup="menu" @click="menu = !menu">
            <span class="grid size-9 flex-none place-items-center rounded-md bg-surface-2 font-display text-sm font-semibold" aria-hidden="true">{{ (auth.me?.name || auth.workspace?.name || '?')[0]?.toUpperCase() }}</span>
            <span class="grid min-w-0 flex-1">
              <span class="truncate text-sm font-semibold">{{ auth.me?.name || 'Akun' }}</span>
              <span class="truncate text-xs text-muted">{{ auth.me?.email || workspaceLine }}</span>
            </span>
            <ChevronsUpDown class="size-4 flex-none text-muted" aria-hidden="true" />
          </button>
        </div>
      </div>
    </aside>

    <PullIndicator :pull="pull" :refreshing="refreshing" :threshold="threshold" />
    <!-- kolom kanan: topbar diam di atas (tinggi tetap), hanya <main> yang scroll -->
    <div class="flex min-h-0 min-w-0 flex-col">
      <!-- ponsel: merek + lonceng · tablet & desktop: lokasi (workspace › halaman), cari, lonceng, tindak lanjut -->
      <header class="flex h-14 shrink-0 items-center gap-3 px-4 print:hidden md:h-16 md:px-6 lg:px-8">
        <div class="flex w-full items-center justify-between md:hidden"><RouterLink to="/dashboard" class="no-underline"><BrandLogo /></RouterLink><NotificationBell /></div>
        <div class="hidden min-w-0 flex-1 items-center gap-3 md:flex">
          <nav aria-label="Lokasi" class="min-w-0 flex-1">
            <p class="flex min-w-0 items-center gap-1.5 text-sm text-muted">
              <span class="truncate">{{ wsName }}</span>
              <ChevronRight class="size-4 flex-none" aria-hidden="true" />
              <span class="truncate font-medium text-fg" aria-current="page">{{ route.meta.title }}</span>
            </p>
          </nav>
          <RouterLink to="/connections" class="hidden h-10 w-64 items-center gap-2 rounded-md border border-line bg-surface px-3 text-sm text-muted no-underline hover:text-fg xl:flex" aria-label="Cari kontak">
            <Search class="size-4 flex-none" aria-hidden="true" /><span class="min-w-0 flex-1 truncate">Cari kontak…</span>
          </RouterLink>
          <NotificationBell />
          <RouterLink to="/follow-up" class="grid size-10 flex-none place-items-center rounded-md text-muted no-underline hover:bg-surface-2 hover:text-fg" aria-label="Perlu tindak lanjut">
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
      :tabs="tabs" :groups="groups.filter((g) => g.label)" :active="active"
      :title="auth.me?.name || 'Akun'" :subtitle="workspaceLine" :initial="(auth.me?.name || auth.workspace?.name || '?')[0]?.toUpperCase()"
    >
      <template #actions>
        <button type="button" class="flex min-h-11 cursor-pointer items-center gap-3 rounded-sm px-3 text-sm text-fg hover:bg-surface-2" @click="toggle"><Moon class="size-5" /> Ganti tema</button>
        <InstallApp link-class="flex min-h-11 w-full items-center gap-3 rounded-sm px-3 text-sm text-fg hover:bg-surface-2 [&_svg]:size-5" />
        <button type="button" class="flex min-h-11 cursor-pointer items-center gap-3 rounded-sm px-3 text-sm text-danger hover:bg-surface-2" @click="auth.askLogout()"><LogOut class="size-5" /> Keluar</button>
      </template>
    </MobileNav>
  </div>
</template>
