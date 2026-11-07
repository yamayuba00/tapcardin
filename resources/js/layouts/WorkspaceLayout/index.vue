<script setup>
// AFD §8 — desktop: sidebar berkelompok (Kartu · Lead · Lainnya) setinggi layar tanpa ikut scroll;
// ponsel: bottom bar 4 item (Beranda · Koneksi · Kartu · Menu). "Menu" membuka lembar berisi semua menu sidebar
// + akun (tema, pasang aplikasi, keluar), jadi tidak ada halaman yang hanya bisa dibuka dari desktop.
// Rute dengan `meta.fullscreen` (editor kartu & lanyard) tampil tanpa sidebar & padding.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import {
  BellRing, Blocks, CalendarDays, ChartColumn, ChevronsUpDown, CreditCard, Gem, House, LayoutDashboard, LayoutGrid, LogOut, Moon, Package, Palette, Settings, ShieldCheck, User, Users, X,
} from 'lucide-vue-next'
import BrandLogo from '@/components/common/BrandLogo/index.vue'
import InstallApp from '@/components/common/InstallApp/index.vue'
import { config } from '@/config'
import { useAuthStore } from '@/stores/auth'
import { useNotificationStore } from '@/stores/notifications'
import { useTheme } from '@/composables/useTheme'

const route = useRoute()
const auth = useAuthStore()
const notifications = useNotificationStore()
const { toggle } = useTheme()

const groups = computed(() => [
  { label: null, items: [{ key: 'dashboard', label: 'Beranda', icon: House, to: '/dashboard' }] },
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
const fullscreen = computed(() => route.meta.fullscreen === true)

// lembar "Menu" ponsel: tertutup saat pindah halaman; tombolnya aktif bila halaman sekarang bukan 3 tab utama
const sheet = ref(false)
watch(() => route.fullPath, () => (sheet.value = false))
const onEsc = (e) => { if (e.key === 'Escape') sheet.value = false }
const inSheet = computed(() => !['dashboard', 'connections', 'cards'].includes(active.value))

const menu = ref(false)
const menuEl = ref(null)
const closeMenu = (e) => { if (menu.value && !menuEl.value?.contains(e.target)) menu.value = false }
onMounted(() => {
  notifications.start()
  document.addEventListener('pointerdown', closeMenu)
  document.addEventListener('keydown', onEsc)
})
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', closeMenu)
  document.removeEventListener('keydown', onEsc)
})
const link = 'group flex min-h-9 cursor-pointer items-center gap-3 rounded-lg px-3 text-[14px] font-medium text-muted no-underline hover:bg-surface-2 hover:text-fg aria-[current=page]:bg-surface-2 aria-[current=page]:text-fg'
</script>

<template>
  <div v-if="fullscreen" class="min-h-dvh"><RouterView /></div>

  <!-- app shell: tinggi dikunci setinggi layar; hanya <main> yang scroll, sidebar diam -->
  <div v-else class="grid h-dvh grid-rows-[minmax(0,1fr)] lg:grid-cols-[248px_minmax(0,1fr)] print:block print:h-auto">
    <aside class="hidden min-h-0 flex-col gap-5 overflow-y-auto overscroll-contain print:!hidden border-r border-line px-4 py-5 scrollbar-none lg:flex" aria-label="Navigasi utama">
      <RouterLink to="/dashboard" class="px-1 no-underline"><BrandLogo /></RouterLink>

      <nav class="grid gap-4">
        <div v-for="(g, gi) in groups" :key="gi" class="grid gap-0.5">
          <p v-if="g.label" class="label mb-1 px-3 !text-[10px]">{{ g.label }}</p>
          <RouterLink v-for="item in g.items" :key="item.key" :to="item.to" :aria-current="active === item.key ? 'page' : undefined" :class="link">
            <component :is="item.icon" class="size-[18px] flex-none group-aria-[current=page]:text-accent-text" aria-hidden="true" />
            <span class="truncate">{{ item.label }}</span>
            <span v-if="item.key === 'dashboard' && notifications.unread" class="ml-auto h-5 min-w-5 rounded-full bg-accent px-1.5 text-center font-mono text-[11px] leading-5 font-semibold text-accent-ink">
              {{ notifications.unread > 99 ? '99+' : notifications.unread }}
            </span>
          </RouterLink>
        </div>
        <div v-if="auth.me?.is_staff" class="grid gap-0.5">
          <p class="label mb-1 px-3 !text-[10px]">Panel</p>
          <a :href="config.adminUrl" :class="link"><ShieldCheck class="size-[18px]" aria-hidden="true" /> Panel pemilik</a>
        </div>
      </nav>

      <!-- akun: workspace, tema, keluar dalam satu menu agar sidebar tetap pendek -->
      <div ref="menuEl" class="relative mt-auto">
        <div v-if="menu" class="absolute inset-x-0 bottom-full mb-2 grid gap-0.5 rounded-md border border-line-strong bg-surface p-1.5" role="menu">
          <RouterLink to="/settings" class="flex min-h-9 cursor-pointer items-center gap-3 rounded-sm px-3 text-sm text-fg no-underline hover:bg-surface-2" role="menuitem" @click="menu = false"><User class="size-4" /> Akun & pengaturan</RouterLink>
          <button type="button" class="flex min-h-9 cursor-pointer items-center gap-3 rounded-sm px-3 text-sm text-fg hover:bg-surface-2" role="menuitem" @click="toggle"><Moon class="size-4" /> Ganti tema</button>
          <InstallApp link-class="flex min-h-9 w-full items-center gap-3 rounded-sm px-3 text-sm text-fg hover:bg-surface-2 [&_svg]:size-4" />
          <button type="button" class="flex min-h-9 cursor-pointer items-center gap-3 rounded-sm px-3 text-sm text-danger hover:bg-surface-2" role="menuitem" @click="auth.logout()"><LogOut class="size-4" /> Keluar</button>
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

    <main ref="mainEl" class="min-h-0 min-w-0 overflow-y-auto overscroll-contain px-4 pt-6 print:overflow-visible print:p-0 pb-[calc(88px+env(safe-area-inset-bottom))] lg:px-8 lg:pt-8 lg:pb-12">
      <div class="grid w-full gap-6">
        <RouterView />
      </div>
    </main>

    <!-- Ponsel: 4 tombol sama lebar; label dipotong (truncate) supaya "Koneksi"/"Lead" tidak mendorong tombol lain.
         Scan kartu (Mode Reader) tidak di navigasi — dibuka dari tombol "Scan kartu" di halaman Koneksi. -->
    <nav class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 print:hidden border-t border-line bg-surface pb-[env(safe-area-inset-bottom)] lg:hidden" aria-label="Navigasi">
      <RouterLink to="/dashboard" :aria-current="active === 'dashboard' ? 'page' : undefined" class="grid min-w-0 justify-items-center gap-1 px-1 pt-2.5 pb-2 text-[11px] font-medium text-muted no-underline aria-[current=page]:text-accent-text">
        <House class="size-[22px]" aria-hidden="true" /><span class="max-w-full truncate">Beranda</span>
      </RouterLink>
      <RouterLink to="/connections" :aria-current="active === 'connections' ? 'page' : undefined" class="grid min-w-0 justify-items-center gap-1 px-1 pt-2.5 pb-2 text-[11px] font-medium text-muted no-underline aria-[current=page]:text-accent-text">
        <Users class="size-[22px]" aria-hidden="true" /><span class="max-w-full truncate">{{ auth.contactLabel }}</span>
      </RouterLink>
      <RouterLink to="/cards" :aria-current="active === 'cards' ? 'page' : undefined" class="grid min-w-0 justify-items-center gap-1 px-1 pt-2.5 pb-2 text-[11px] font-medium text-muted no-underline aria-[current=page]:text-accent-text">
        <CreditCard class="size-[22px]" aria-hidden="true" /><span class="max-w-full truncate">Kartu</span>
      </RouterLink>
      <button
        type="button" :aria-expanded="sheet" aria-controls="mobile-menu" :aria-current="inSheet ? 'page' : undefined"
        class="relative grid min-w-0 cursor-pointer justify-items-center gap-1 px-1 pt-2.5 pb-2 text-[11px] font-medium text-muted aria-[current=page]:text-accent-text"
        @click="sheet = !sheet"
      >
        <LayoutGrid class="size-[22px]" aria-hidden="true" /><span class="max-w-full truncate">Menu</span>
      </button>
    </nav>

    <!-- Lembar menu ponsel: semua menu sidebar dalam ubin 3 kolom per kelompok, lalu akun. Di bawah bilah navigasi
         (z-20 < z-30) sehingga tombol "Menu" tetap terlihat untuk menutupnya. -->
    <template v-if="sheet">
      <div class="fixed inset-0 z-20 bg-scrim lg:hidden" aria-hidden="true" @click="sheet = false"></div>
      <section
        id="mobile-menu" role="dialog" aria-modal="true" aria-label="Menu"
        class="fixed inset-x-0 bottom-0 z-20 grid max-h-[85dvh] animate-rise gap-4 overflow-y-auto overscroll-contain rounded-t-lg border-t border-line-strong bg-surface px-4 pt-3 pb-[calc(76px+env(safe-area-inset-bottom))] lg:hidden"
      >
        <div class="flex items-center justify-between">
          <span class="flex min-w-0 items-center gap-3">
            <span class="grid size-9 flex-none place-items-center rounded-sm bg-surface-2 font-display text-sm font-semibold" aria-hidden="true">{{ (auth.me?.name || auth.workspace?.name || '?')[0]?.toUpperCase() }}</span>
            <span class="grid min-w-0">
              <span class="truncate text-sm font-semibold">{{ auth.me?.name || 'Akun' }}</span>
              <span class="label truncate !text-[10px] !tracking-[.12em]">{{ auth.workspace?.type === 'personal' ? 'Personal' : auth.workspace?.name }} · {{ auth.workspace?.plan_name }}</span>
            </span>
          </span>
          <button type="button" class="grid size-10 flex-none cursor-pointer place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-fg" aria-label="Tutup menu" @click="sheet = false"><X class="size-5" /></button>
        </div>
        <!-- Beranda sudah ada di bilah bawah → hanya kelompok berlabel -->
        <nav v-for="g in groups.filter((x) => x.label)" :key="g.label" class="grid gap-2" :aria-label="g.label">
          <p class="label !text-[10px]">{{ g.label }}</p>
          <div class="grid grid-cols-3 gap-2">
            <RouterLink
              v-for="item in g.items" :key="item.key" :to="item.to" :aria-current="active === item.key ? 'page' : undefined"
              class="relative grid min-h-[72px] content-center justify-items-center gap-1.5 rounded-md border border-line px-1 py-2 text-center text-xs font-medium text-fg no-underline hover:bg-surface-2 aria-[current=page]:border-accent aria-[current=page]:text-accent-text"
            >
              <component :is="item.icon" class="size-[22px]" aria-hidden="true" />
              <span class="line-clamp-2 leading-tight">{{ item.label }}</span>
              <span v-if="item.key === 'dashboard' && notifications.unread" class="absolute top-1.5 right-1.5 h-5 min-w-5 rounded-full bg-accent px-1.5 text-center font-mono text-[11px] leading-5 font-semibold text-accent-ink">
                {{ notifications.unread > 99 ? '99+' : notifications.unread }}
              </span>
            </RouterLink>
          </div>
        </nav>
        <div class="grid gap-0.5 border-t border-line pt-3">
          <a v-if="auth.me?.is_staff" :href="config.adminUrl" class="flex min-h-11 items-center gap-3 rounded-sm px-3 text-sm text-fg no-underline hover:bg-surface-2"><ShieldCheck class="size-5" /> Panel pemilik</a>
          <button type="button" class="flex min-h-11 cursor-pointer items-center gap-3 rounded-sm px-3 text-sm text-fg hover:bg-surface-2" @click="toggle"><Moon class="size-5" /> Ganti tema</button>
          <InstallApp link-class="flex min-h-11 w-full items-center gap-3 rounded-sm px-3 text-sm text-fg hover:bg-surface-2 [&_svg]:size-5" />
          <button type="button" class="flex min-h-11 cursor-pointer items-center gap-3 rounded-sm px-3 text-sm text-danger hover:bg-surface-2" @click="auth.logout()"><LogOut class="size-5" /> Keluar</button>
        </div>
      </section>
    </template>
  </div>
</template>
