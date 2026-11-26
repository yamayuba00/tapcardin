<script setup>
// U-01 / DBD §10.1 — KPI, grafik tap 7/30 hari, aktivitas terbaru realtime. Tata letak mengikuti referensi dashboard:
// ≥1280 tiga kolom (grafik 2/3 + aksi cepat 1/3), 768–1279 satu kolom bertumpuk, <768 satu kolom + baris kartu.
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { BellRing, Bookmark, CircleAlert, CreditCard, Eye, Nfc, Package, ScanLine, Share2, UserPlus, Users } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import InstallApp from '@/components/common/InstallApp/index.vue'
import OnboardingChecklist from '@/components/onboarding/OnboardingChecklist/index.vue'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppEmpty from '@/components/ui/AppEmpty/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import CompanySummary from '@/components/company/CompanySummary/index.vue'
import LeadFunnel from '@/components/dashboard/LeadFunnel/index.vue'
import StatTile from '@/components/dashboard/StatTile/index.vue'
import TrendChart from '@/components/dashboard/TrendChart/index.vue'
import QuickActions from '@/components/dashboard/QuickActions/index.vue'
import ActivityTable from '@/components/dashboard/ActivityTable/index.vue'
import { companyApi, dashboardApi } from '@/api/workspace'
import { useAsync } from '@/composables/useAsync'
import { groupRecent } from '@/lib/activity'
import { num, relative } from '@/lib/format'
import { deltaPill, lastDays } from '@/lib/dashboard'
import { useAuthStore } from '@/stores/auth'
import { config } from '@/config'
import { useNotificationStore } from '@/stores/notifications'

const auth = useAuthStore()
const notifications = useNotificationStore()
// workspace perusahaan: owner/admin melihat ringkasan perusahaan di atas beranda pribadinya
const companyAdmin = computed(() => auth.workspace && auth.workspace.type !== 'personal' && auth.can('analytics.view.all'))
const { data, error, reload } = useAsync(() => dashboardApi.summary())
const { data: company, error: companyError } = useAsync(() => (companyAdmin.value ? companyApi.summary() : Promise.resolve(null)))
const now = ref(new Date())
const RECENT = 5 // aktivitas yang tampil sebelum "Tampilkan semua"
const allRecent = ref(false)
const range = ref(30) // rentang grafik: 7 atau 30 hari (API menyediakan 30 hari harian)
const grouped = computed(() => groupRecent(data.value?.recent))
const recent = computed(() => (allRecent.value ? grouped.value : grouped.value.slice(0, RECENT)))
let timer
let unsubscribe

onMounted(() => {
  timer = setInterval(() => (now.value = new Date()), 30000)
  unsubscribe = notifications.subscribe((type, d, { id, update } = {}) => {
    if (!data.value) return
    if (type === 'tap') {
      // tap gabungan (id notifikasi sama) memperbarui baris yang sudah ada, bukan menambah baris baru
      const row = { type: 'tap', at: d.at, nid: id, title: (d.count ?? 1) > 1 ? `${d.count} orang membuka profil Anda` : 'Profil Anda dibuka', meta: [d.label, ...(d.cities ?? [d.city])].filter(Boolean) }
      const i = update && id ? data.value.recent.findIndex((r) => r.nid === id) : -1
      if (i >= 0) data.value.recent.splice(i, 1)
      data.value.recent.unshift(row)
      data.value.kpi.taps_7++
      data.value.kpi.taps_30++
      const last = data.value.series.at(-1)
      if (last) last.value++
    }
    if (type === 'lead_new') {
      data.value.recent.unshift({ type: 'connection', at: new Date().toISOString(), title: `${d.name} terhubung`, meta: [d.company].filter(Boolean), id: d.id })
      data.value.kpi.connections_30++
    }
    data.value.recent = data.value.recent.slice(0, 10)
  })
})
onUnmounted(() => { clearInterval(timer); unsubscribe?.() })

const empty = computed(() => data.value && data.value.kpi.taps_30 === 0 && data.value.recent.length === 0)
const firstName = computed(() => (auth.me?.name ?? '').split(' ')[0])
const tapPill = computed(() => (data.value ? deltaPill(data.value.kpi.taps_7, data.value.kpi.taps_prev_7) : null))
const chartSeries = computed(() => lastDays(data.value?.series ?? [], range.value))
const kpiReady = computed(() => data.value && (!companyAdmin.value || company.value || companyError.value))

// KPI: perorangan (Tap 7 hari, Pengunjung, Simpan, Koneksi) atau perusahaan (Kartu aktif, Tap, Lead, Follow-up terlambat)
const kpis = computed(() => {
  const k = data.value?.kpi
  if (!k) return []
  if (companyAdmin.value && company.value) {
    const c = company.value
    return [
      { key: 'cards', icon: CreditCard, label: 'Kartu aktif', value: num(c.cards_active), sub: c.cards_max ? `dari ${num(c.cards_max)} batas paket` : '' },
      { key: 'taps', icon: Nfc, label: 'Tap 30 hari', value: num(c.taps_30), sub: '30 hari terakhir' },
      { key: 'leads', icon: UserPlus, label: 'Lead baru', value: num(c.leads_30), sub: '30 hari terakhir' },
      { key: 'fu', icon: BellRing, label: 'Follow-up terlambat', value: num(data.value.attention?.followups_overdue ?? 0), sub: 'Pengingat Anda' },
    ]
  }
  return [
    { key: 'tap7', icon: Nfc, label: 'Tap 7 hari', value: num(k.taps_7), delta: tapPill.value, sub: tapPill.value ? 'vs 7 hari sebelumnya' : 'Minggu lalu belum ada tap' },
    { key: 'visitors', icon: Eye, label: 'Pengunjung unik', value: num(k.visitors_30), sub: '30 hari terakhir' },
    { key: 'saved', icon: Bookmark, label: 'Simpan kontak', value: num(k.saved_30), sub: k.save_rate !== null ? `${k.save_rate}% dari tap` : 'Belum ada tap' },
    { key: 'conn', icon: Users, label: `${auth.contactLabel} 30 hari`, value: num(k.connections_30), sub: '30 hari terakhir' },
  ]
})

const quickItems = computed(() => [
  data.value?.share_token
    ? { label: 'Bagikan kartu', sub: 'Kirim kartu aktif Anda', icon: Share2, to: `/cards/${data.value.share_token}/share` }
    : { label: 'Bagikan kartu', sub: 'Lihat kartu Anda', icon: Share2, to: '/cards' },
  { label: 'Pesan kartu', sub: 'Keranjang & checkout', icon: Package, to: '/orders/new' },
  { label: 'Tambah lead', sub: 'Scan kartu orang lain', icon: ScanLine, to: '/scan' },
])
const attentionCount = computed(() => (data.value?.attention?.followups_overdue ?? 0) + (data.value?.attention?.cards_expiring ?? 0))
</script>

<template>
  <header class="grid gap-1">
    <h1 class="font-display text-2xl font-semibold sm:text-3xl">Selamat datang{{ firstName ? `, ${firstName}` : '' }}</h1>
    <p class="text-sm text-muted">Ringkasan kartu dan kontak Anda</p>
  </header>

  <!-- masuk pertama kali lewat kartu + kode akun → ajak hubungkan Google supaya bisa masuk tanpa kartu -->
  <div v-if="config.googleEnabled && auth.me && !auth.me.google_linked" class="flex flex-wrap items-center gap-3 rounded-md border border-line-strong bg-surface-2 px-4 py-3 text-sm">
    <span class="min-w-0 flex-1"><b>Hubungkan akun Google Anda.</b> <span class="text-muted">Setelah terhubung, Anda bisa masuk dengan Google kapan saja — tanpa perlu kartu.</span></span>
    <AppButton variant="secondary" :href="`/auth/google/redirect?link=1&redirect=${encodeURIComponent('/settings')}`">Hubungkan Google</AppButton>
  </div>

  <CompanySummary v-if="companyAdmin" />

  <div v-if="error" class="flex items-center gap-3 rounded-md border border-line-strong bg-surface-2 px-4 py-3 text-sm" role="alert">
    <CircleAlert class="size-5 text-danger" /><span class="flex-1">{{ error.message }}</span>
    <AppButton variant="ghost" @click="reload">Muat ulang</AppButton>
  </div>

  <div v-if="!kpiReady && !error" class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" aria-busy="true">
    <AppSkeleton v-for="i in 4" :key="i" class="!h-32" />
  </div>

  <template v-if="kpiReady">
    <OnboardingChecklist v-if="data.onboarding" :data="data.onboarding" @dismissed="data.onboarding = null" />
    <InstallApp variant="banner" />

    <!-- KPI: 2 kolom di ponsel & tablet (2×2), 4 kolom di desktop -->
    <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
      <StatTile v-for="k in kpis" :key="k.key" :icon="k.icon" :label="k.label" :value="k.value" :sub="k.sub" :delta="k.delta ?? null" />
    </div>

    <AppEmpty v-if="empty" text="Belum ada tap. Coba tempel kartu ke ponsel Anda sendiri.">
      <AppButton to="/cards">Lihat kartu</AppButton>
    </AppEmpty>

    <template v-else>
      <div class="grid gap-4 xl:grid-cols-3 xl:items-start">
        <!-- grafik tap: 2/3 lebar di desktop, penuh di tablet & ponsel -->
        <section class="grid min-w-0 content-start gap-4 rounded-md border border-line bg-surface p-4 sm:p-5 xl:col-span-2" aria-labelledby="tap-chart-title">
          <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="grid min-w-0 gap-1">
              <h2 id="tap-chart-title" class="text-sm font-medium text-muted">Tap {{ range }} hari</h2>
              <div class="flex flex-wrap items-center gap-3">
                <span class="num text-3xl leading-none font-semibold">{{ num(range === 7 ? data.kpi.taps_7 : data.kpi.taps_30) }}</span>
                <span
                  v-if="tapPill"
                  :class="['inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium', tapPill.up ? 'border-ok text-ok' : 'border-danger text-danger']"
                >
                  <span aria-hidden="true">{{ tapPill.up ? '▲' : '▼' }}</span>{{ tapPill.value }}%<span class="sr-only">{{ tapPill.up ? 'naik' : 'turun' }}</span>
                </span>
              </div>
              <p class="text-xs text-muted">{{ tapPill ? '7 hari terakhir vs 7 hari sebelumnya' : 'Total tap dalam periode ini' }}</p>
            </div>
            <div role="group" aria-label="Rentang grafik" class="flex gap-1 rounded-md border border-line p-1">
              <button
                v-for="r in [7, 30]" :key="r" type="button" :aria-pressed="range === r"
                :class="['min-h-8 min-w-12 cursor-pointer rounded-sm px-3 text-sm font-medium', range === r ? 'bg-surface-2 text-fg' : 'text-muted hover:text-fg']"
                @click="range = r"
              >{{ r }} hari</button>
            </div>
          </header>
          <TrendChart :series="chartSeries" :label="`Tap ${range} hari`" />
        </section>

        <div class="grid min-w-0 content-start gap-4">
          <QuickActions :items="quickItems" />
          <AppPanel title="Perlu tindak lanjut">
            <ul v-if="attentionCount" class="grid gap-2">
              <li v-if="data.attention.followups_overdue">
                <RouterLink to="/follow-up" class="flex min-h-14 items-center gap-3 rounded-md border border-line px-3 py-2.5 no-underline hover:bg-surface-2">
                  <span class="grid size-10 flex-none place-items-center rounded-md bg-surface-2 text-accent-text" aria-hidden="true"><BellRing class="size-5" /></span>
                  <span class="grid min-w-0 flex-1"><span class="truncate font-medium text-fg">Follow-up terlambat</span><span class="text-xs text-muted sm:text-sm">Pengingat pribadi</span></span>
                  <span class="num font-semibold">{{ num(data.attention.followups_overdue) }}</span>
                </RouterLink>
              </li>
              <li v-if="data.attention.cards_expiring">
                <RouterLink to="/cards" class="flex min-h-14 items-center gap-3 rounded-md border border-line px-3 py-2.5 no-underline hover:bg-surface-2">
                  <span class="grid size-10 flex-none place-items-center rounded-md bg-surface-2 text-accent-text" aria-hidden="true"><CreditCard class="size-5" /></span>
                  <span class="grid min-w-0 flex-1"><span class="truncate font-medium text-fg">Kartu berakhir dalam 30 hari</span><span class="text-xs text-muted sm:text-sm">Kartu fisik</span></span>
                  <span class="num font-semibold">{{ num(data.attention.cards_expiring) }}</span>
                </RouterLink>
              </li>
            </ul>
            <p v-else class="text-sm text-muted">Tidak ada yang perlu ditindaklanjuti.</p>
          </AppPanel>
        </div>
      </div>

      <div class="grid gap-4 xl:grid-cols-3 xl:items-start">
        <!-- aktivitas: 2/3 lebar di desktop; di bawahnya daftar kartu (tablet bertumpuk, ponsel baris kartu) -->
        <div class="min-w-0 xl:col-span-2">
          <ActivityTable :rows="recent" :now="now" :more-count="grouped.length > RECENT ? grouped.length - RECENT : 0" :expanded="allRecent" @toggle="allRecent = !allRecent">
            <template #actions><AppButton variant="ghost" to="/connections">Lihat semua {{ auth.contactLabel.toLowerCase() }}</AppButton></template>
          </ActivityTable>
        </div>

        <AppPanel v-if="data.cards.list.length" title="Kartu saya">
          <template #actions><span class="text-sm text-muted">{{ data.cards.active }} aktif</span></template>
          <ul class="grid gap-2">
            <li v-for="c in data.cards.list" :key="c.token">
              <div class="flex min-h-14 items-center gap-3 rounded-md border border-line px-3 py-2.5">
                <span class="grid size-10 flex-none place-items-center rounded-md bg-surface-2 text-accent-text" aria-hidden="true"><CreditCard class="size-5" /></span>
                <RouterLink :to="`/cards/${c.token}`" class="grid min-w-0 flex-1 no-underline">
                  <span class="truncate font-medium text-fg">{{ c.label || 'Kartu ' + c.short_code }}</span>
                  <span class="truncate text-xs text-muted sm:text-sm">{{ c.profile?.label }} · tap terakhir {{ relative(c.last_tap_at, now) }}</span>
                </RouterLink>
                <AppBadge :tone="c.tone">{{ c.status_label }}</AppBadge>
              </div>
            </li>
          </ul>
        </AppPanel>
      </div>
    </template>

    <AppPanel v-if="!empty && data.funnel?.some((s) => s.count)" title="Funnel lead"><LeadFunnel :stages="data.funnel" /></AppPanel>
  </template>
</template>
