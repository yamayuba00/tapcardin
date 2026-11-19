<script setup>
// AFD §8 — desktop: sidebar berkelompok (Kartu · Lead · Lainnya) setinggi layar tanpa ikut scroll;
// ponsel: bottom bar 4 item (Beranda · Koneksi · Kartu · Menu). "Menu" membuka lembar berisi semua menu sidebar
// + akun (tema, pasang aplikasi, keluar), jadi tidak ada halaman yang hanya bisa dibuka dari desktop.
// Rute dengan `meta.fullscreen` (editor kartu & lanyard) tampil tanpa sidebar & padding.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import {
  BellRing, Blocks, Building2, CalendarDays, ChartColumn, ChevronsUpDown, CreditCard, Gem, House, LayoutDashboard, LogOut, Moon, Package, Palette, Settings, User, Users, Webhook,
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
  ...(auth.workspace && auth.workspace.type !== 'personal' && (auth.can('members.invite') || auth.can('cards.assign.*') || canWebhook.value)
    ? [{ label: 'Perusahaan', items: [
      (auth.can('members.invite') || auth.can('cards.assign.*')) && { key: 'members', label: auth.can('members.invite') ? 'Karyawan' : 'Kartu tim', icon: Building2, to: '/members' },
      canWebhook.value && { key: 'webhooks', label: 'Webhook', icon: Webhook, to: '/webhooks' },
    ].filter(Boolean) }] : []),
  { label: 'Kartu', items: [
    { key: 'cards', label: 'Kartu saya', icon: CreditCard, to: '/cards' },
    { key: 'designs', label: 'Desain & merchandise', icon: Palette, to: '/designs' },
    { key: 'orders', label: 'Pesanan', icon: Package, to: '/orders' },
  ] },
  { label: auth.contactLabel, items: [
    { key: 'connections', label: `Daftar ${auth.contactLabel.toLowerCase()}`, icon: Users, to: '/connections' },
    { key: 'lead-dashboard', label: 'Dashboard', icon: LayoutDashboard, to: '/connections/dashboard' },
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
// konten di-scroll di <main>, bukan window → kembalikan ke atas saat pindah halaman
const mainEl = ref(null)
watch(() => route.path, () => mainEl.value?.scrollTo({ top: 0 }))

// tarik ke bawah (ponsel/PWA) → halaman aktif dipasang ulang sehingga datanya dimuat ulang
const refreshKey = ref(0)
const { pull, refreshing, threshold } = usePullToRefresh(mainEl, async () => {
  refreshKey.value++
  await new Promise((r) => setTimeout(r, 600))
})
const fullscreen = computed(() => route.meta.fullscreen === true)

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
const link = 'group flex min-h-9 cursor-pointer items-center gap-3 rounded-lg px-3 text-[14px] font-medium text-muted no-underline hover:bg-surface-2 hover:text-fg aria-[current=page]:bg-surface-2 aria-[current=page]:text-fg'
</script>

<template>
  <div v-if="fullscreen" class="min-h-dvh"><RouterView /></div>

  <!-- app shell: tinggi dikunci setinggi layar; hanya <main> yang scroll, sidebar diam -->
  <div v-else class="grid h-dvh grid-rows-[minmax(0,1fr)] lg:grid-cols-[248px_minmax(0,1fr)] print:block print:h-auto">
    <aside class="hidden min-h-0 flex-col gap-5 overflow-y-auto overscroll-contain print:!hidden border-r border-line px-4 py-5 scrollbar-none lg:flex" aria-label="Navigasi utama">
      <div class="flex items-center justify-between gap-2"><RouterLink to="/dashboard" class="px-1 no-underline"><BrandLogo /></RouterLink><NotificationBell /></div>

      <nav class="grid gap-4">
        <div v-for="(g, gi) in groups" :key="gi" class="grid gap-0.5">
          <p v-if="g.label" class="label mb-1 px-3 !text-[10px]">{{ g.label }}</p>
          <RouterLink v-for="item in g.items" :key="item.key" :to="item.to" :aria-current="active === item.key ? 'page' : undefined" :class="link">
            <component :is="item.icon" class="size-[18px] flex-none group-aria-[current=page]:text-accent-text" aria-hidden="true" />
            <span class="truncate">{{ item.label }}</span>
          </RouterLink>
        </div>
      </nav>

      <!-- akun: workspace, tema, keluar dalam satu menu agar sidebar tetap pendek -->
      <div ref="menuEl" class="relative mt-auto">
        <div v-if="menu" class="absolute inset-x-0 bottom-full mb-2 grid gap-0.5 rounded-md border border-line-strong bg-surface p-1.5" role="menu">
          <RouterLink to="/settings" class="flex min-h-9 cursor-pointer items-center gap-3 rounded-sm px-3 text-sm text-fg no-underline hover:bg-surface-2" role="menuitem" @click="menu = false"><User class="size-4" /> Akun & pengaturan</RouterLink>
          <button type="button" class="flex min-h-9 cursor-pointer items-center gap-3 rounded-sm px-3 text-sm text-fg hover:bg-surface-2" role="menuitem" @click="toggle"><Moon class="size-4" /> Ganti tema</button>
          <InstallApp link-class="flex min-h-9 w-full items-center gap-3 rounded-sm px-3 text-sm text-fg hover:bg-surface-2 [&_svg]:size-4" />
          <button type="button" class="flex min-h-9 cursor-pointer items-center gap-3 rounded-sm px-3 text-sm text-danger hover:bg-surface-2" role="menuitem" @click="auth.askLogout()"><LogOut class="size-4" /> Keluar</button>
        </div>
        <button type="button" class="flex w-full cursor-pointer items-center gap-3 rounded-md border border-line-strong bg-surface p-2.5 text-left hover:bg-surface-2" :aria-expanded="menu" aria-haspopup="menu" @click="menu = !menu">
          <span class="grid size-8 flex-none place-items-center rounded-sm bg-surface-2 font-display text-sm font-semibold" aria-hidden="true">{{ (auth.me?.name || auth.workspace?.name || '?')[0]?.toUpperCase() }}</span>
          <span class="grid min-w-0 flex-1">
            <span class="truncate text-sm font-semibold">{{ auth.me?.name || 'Akun' }}</span>
            <span class="label truncate !text-[10px] !tracking-[.12em]">{{ auth.workspace?.type === 'personal' ? 'Personal' : auth.workspace?.name }} · {{ auth.workspace?.plan_name }}</span>
          </span>
          <ChevronsUpDown class="size-4 flex-none text-muted" aria-hidden="true" />
        </button>
      </div>
    </aside>

    <PullIndicator :pull="pull" :refreshing="refreshing" :threshold="threshold" />
    <main ref="mainEl" class="min-h-0 min-w-0 overflow-y-auto overscroll-contain px-4 pt-6 print:overflow-visible print:p-0 pb-[calc(88px+env(safe-area-inset-bottom))] lg:px-8 lg:pt-8 lg:pb-12">
      <div class="grid w-full gap-6">
        <!-- ponsel: bilah atas ringkas dengan lonceng notifikasi -->
        <div class="-mt-2 -mb-2 flex items-center justify-between lg:hidden print:hidden"><RouterLink to="/dashboard" class="no-underline"><BrandLogo /></RouterLink><NotificationBell /></div>
        <RouterView v-slot="{ Component }"><component :is="Component" :key="refreshKey" /></RouterView>
      </div>
    </main>

    <MobileNav
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
