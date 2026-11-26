<script setup>
// PL-01 — dashboard panel pemilik dengan tata letak referensi Beranda: KPI berikon, grafik tap 7/30 hari,
// aksi cepat, perlu tindak lanjut, dan tabel batch berjalan. Data dari GET /api/dashboard (tanpa perubahan PHP).
// ≥1280 tiga kolom (grafik 2/3 + panel 1/3), 768–1279 bertumpuk, <768 satu kolom. Tabel hanya scroll di dalam kartunya.
import { computed, ref } from 'vue'
import { BadgeCheck, Boxes, Building2, CircleAlert, CreditCard, Nfc, Receipt, ShoppingCart, Stamp, Users, Wallet } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppEmpty from '@/components/ui/AppEmpty/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import StatTile from '@/components/dashboard/StatTile/index.vue'
import TrendChart from '@/components/dashboard/TrendChart/index.vue'
import QuickActions from '@/components/dashboard/QuickActions/index.vue'
import TimeBars from '@/components/charts/TimeBars/index.vue'
import { adminApi } from '@/api/admin'
import { useAsync } from '@/composables/useAsync'
import { useAuthStore } from '@/stores/auth'
import { CARD_STATUS, num, rupiah } from '@/lib/format'
import { deltaPill, lastDays } from '@/lib/dashboard'
import { attentionItems, periodDelta, periodSums } from '@/lib/adminDashboard'

const auth = useAuthStore()
const { data: m, error, reload } = useAsync(() => adminApi.dashboard())
const range = ref(30) // API menyediakan 30 hari harian → rentang 7 dan 30 hari
const total = (cards) => Object.values(cards).reduce((a, b) => a + b, 0)

const series = computed(() => m.value?.series ?? [])
const sums = computed(() => periodSums(series.value, 7))
const chartSeries = computed(() => lastDays(series.value, range.value))
// pembanding hanya untuk 7 hari (7 hari terakhir vs 7 hari sebelumnya dari deret yang sama)
const tapPill = computed(() => (range.value === 7 ? periodDelta(series.value, 7) : null))
const tapTotal = computed(() => (range.value === 7 ? sums.value.current : (m.value?.taps_30 ?? 0)))

const attention = computed(() => attentionItems(m.value))

const quick = computed(() => [
  { label: 'Workspace', sub: 'Kelola tenant & paket', icon: Building2, to: '/tenants', perm: 'tenants.view' },
  { label: 'Pesanan', sub: 'Pesanan masuk & tinjauan', icon: ShoppingCart, to: '/orders', perm: 'orders.view' },
  { label: 'Persetujuan', sub: 'Permintaan & refund', icon: Stamp, to: '/approvals', perm: 'finance.view' },
  { label: 'Batch produksi', sub: 'Batch yang sedang berjalan', icon: Boxes, to: '/batches', perm: 'production.manage' },
  { label: 'Jual kartu', sub: 'Aktivasi langsung', icon: BadgeCheck, to: '/aktivasi-kartu', perm: 'cards.manage' },
].filter((i) => auth.can(i.perm)))

const kpis = computed(() => {
  const d = m.value
  if (!d) return []
  return [
    { key: 'tap7', icon: Nfc, label: 'Tap 7 hari', value: num(sums.value.current), delta: periodDelta(series.value, 7), sub: periodDelta(series.value, 7) ? 'vs 7 hari sebelumnya' : 'Pembanding belum tersedia' },
    { key: 'users', icon: Users, label: 'Pengguna', value: num(d.users), sub: `+${num(d.users_7)} dalam 7 hari` },
    { key: 'cards', icon: CreditCard, label: 'Kartu aktif', value: num(d.cards.active), sub: `dari ${num(total(d.cards))} kartu` },
    { key: 'activation', icon: BadgeCheck, label: 'Aktivasi ≤ 7 hari', value: d.activation_rate !== null ? `${d.activation_rate}%` : '—', sub: 'Target ≥ 85%' },
  ]
})

const businessKpis = computed(() => {
  const b = m.value?.business
  if (!b) return []
  return [
    { key: 'mrr', icon: Wallet, label: 'MRR', value: rupiah(b.mrr), sub: `ARR ${rupiah(b.arr)}` },
    { key: 'rev', icon: Wallet, label: 'Pendapatan bulan ini', value: rupiah(b.revenue_month), sub: 'vs bulan sebelumnya', delta: deltaPill(b.revenue_month, b.revenue_prev_month) },
    { key: 'subs', icon: Users, label: 'Langganan aktif', value: num(b.active_subscriptions), sub: b.past_due ? `${num(b.past_due)} menunggak` : 'Tidak ada tunggakan' },
    { key: 'open', icon: Receipt, label: 'Tagihan terbuka', value: num(b.open_invoices), sub: rupiah(b.open_amount) },
  ]
})
</script>

<template>
  <header class="grid gap-1">
    <h1 class="font-display text-2xl font-semibold sm:text-3xl">Dashboard</h1>
    <p class="text-sm text-muted">Kesehatan bisnis dan operasional platform</p>
  </header>

  <div v-if="error" class="flex items-center gap-3 rounded-md border border-line-strong bg-surface-2 px-4 py-3 text-sm" role="alert">
    <CircleAlert class="size-5 text-danger" /><span class="flex-1">{{ error.message }}</span>
    <AppButton variant="ghost" @click="reload">Muat ulang</AppButton>
  </div>
  <div v-if="!m && !error" class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" aria-busy="true">
    <AppSkeleton v-for="i in 4" :key="i" class="!h-32" />
  </div>

  <template v-if="m">
    <!-- KPI: 2×2 di ponsel & tablet, 4 kolom di desktop -->
    <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
      <StatTile v-for="k in kpis" :key="k.key" :icon="k.icon" :label="k.label" :value="k.value" :sub="k.sub" :delta="k.delta ?? null" />
    </div>

    <div class="grid gap-4 xl:grid-cols-3 xl:items-start">
      <!-- grafik tap: 2/3 lebar di desktop, penuh di tablet & ponsel -->
      <section class="grid min-w-0 content-start gap-4 rounded-md border border-line bg-surface p-4 sm:p-5 xl:col-span-2" aria-labelledby="tap-chart-title">
        <header class="flex flex-wrap items-start justify-between gap-3">
          <div class="grid min-w-0 gap-1">
            <h2 id="tap-chart-title" class="text-sm font-medium text-muted">Tap {{ range }} hari</h2>
            <div class="flex flex-wrap items-center gap-3">
              <span class="num text-3xl leading-none font-semibold">{{ num(tapTotal) }}</span>
              <span
                v-if="tapPill"
                :class="['inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium', tapPill.up ? 'border-ok text-ok' : 'border-danger text-danger']"
              >
                <span aria-hidden="true">{{ tapPill.up ? '▲' : '▼' }}</span>{{ tapPill.value }}%<span class="sr-only">{{ tapPill.up ? 'naik' : 'turun' }}</span>
              </span>
            </div>
            <p class="text-xs text-muted">{{ tapPill ? '7 hari terakhir vs 7 hari sebelumnya' : `Total tap seluruh platform · hari ini ${num(m.taps_today)}` }}</p>
          </div>
          <div role="group" aria-label="Rentang grafik" class="flex gap-1 rounded-md border border-line p-1">
            <button
              v-for="r in [7, 30]" :key="r" type="button" :aria-pressed="range === r"
              :class="['min-h-8 min-w-12 cursor-pointer rounded-sm px-3 text-sm font-medium', range === r ? 'bg-surface-2 text-fg' : 'text-muted hover:text-fg']"
              @click="range = r"
            >{{ r }} hari</button>
          </div>
        </header>
        <TrendChart :series="chartSeries" :label="`Tap ${range} hari seluruh platform`" />
      </section>

      <div class="grid min-w-0 content-start gap-4">
        <QuickActions v-if="quick.length" :items="quick" />
        <AppPanel title="Perlu tindak lanjut">
          <ul v-if="attention.length" class="grid gap-2">
            <li v-for="t in attention" :key="t.key">
              <RouterLink :to="t.to" class="flex min-h-14 items-center gap-3 rounded-md border border-line px-3 py-2.5 no-underline hover:bg-surface-2">
                <span class="grid size-10 flex-none place-items-center rounded-md bg-surface-2 text-accent-text" aria-hidden="true"><CircleAlert class="size-5" /></span>
                <span class="grid min-w-0 flex-1"><span class="truncate font-medium text-fg">{{ t.label }}</span><span class="truncate text-xs text-muted sm:text-sm">{{ t.sub }}</span></span>
                <span class="num font-semibold">{{ num(t.n) }}</span>
              </RouterLink>
            </li>
          </ul>
          <p v-else class="text-sm text-muted">Tidak ada yang perlu ditindaklanjuti.</p>
        </AppPanel>
      </div>
    </div>

    <div class="grid gap-4 xl:grid-cols-3 xl:items-start">
      <!-- tabel batch: di dalam kartu, scroll hanya di kartu; ponsel = baris kartu -->
      <section class="min-w-0 rounded-md border border-line bg-surface p-4 sm:p-5 xl:col-span-2" aria-labelledby="batch-title">
        <header class="mb-3 flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
          <h2 id="batch-title" class="font-sans text-lg font-semibold">Batch produksi berjalan</h2>
          <AppButton variant="ghost" to="/batches">Semua batch</AppButton>
        </header>
        <div v-if="m.batches_open.length" class="hidden overflow-x-auto md:block">
          <table class="w-full min-w-[480px] text-sm">
            <thead>
              <tr class="border-b border-line text-left text-xs text-muted">
                <th scope="col" class="py-2 pr-3 font-medium">Kode batch</th>
                <th scope="col" class="py-2 pr-3 font-medium">Jumlah</th>
                <th scope="col" class="py-2 pr-3 font-medium">Chip</th>
                <th scope="col" class="py-2 text-right font-medium">Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="b in m.batches_open" :key="b.id" class="border-b border-line last:border-b-0">
                <td class="py-3 pr-3"><RouterLink :to="`/batches/${b.id}`" class="font-medium !text-fg">{{ b.code }}</RouterLink></td>
                <td class="py-3 pr-3 num text-muted">{{ num(b.quantity) }} kartu</td>
                <td class="py-3 pr-3 font-mono text-muted">{{ b.chip.toUpperCase() }}</td>
                <td class="py-3 text-right"><AppBadge tone="info">{{ b.status_label }}</AppBadge></td>
              </tr>
            </tbody>
          </table>
        </div>
        <ul v-if="m.batches_open.length" class="grid gap-2 md:hidden">
          <li v-for="b in m.batches_open" :key="b.id">
            <RouterLink :to="`/batches/${b.id}`" class="flex min-h-14 items-center gap-3 rounded-md border border-line p-3 no-underline hover:bg-surface-2">
              <span class="grid min-w-0 flex-1">
                <span class="truncate font-medium text-fg">{{ b.code }}</span>
                <span class="truncate text-xs text-muted">{{ num(b.quantity) }} kartu · {{ b.chip.toUpperCase() }}</span>
              </span>
              <AppBadge tone="info">{{ b.status_label }}</AppBadge>
            </RouterLink>
          </li>
        </ul>
        <AppEmpty v-if="!m.batches_open.length" text="Tidak ada batch yang sedang berjalan." />
      </section>

      <AppPanel title="Kartu per status">
        <ul class="grid gap-1">
          <li v-for="(n, s) in m.cards" :key="s" class="flex min-h-11 items-center justify-between border-b border-line last:border-b-0">
            <AppBadge :tone="CARD_STATUS[s].tone">{{ CARD_STATUS[s].label }}</AppBadge><span class="num font-semibold">{{ num(n) }}</span>
          </li>
        </ul>
      </AppPanel>
    </div>

    <template v-if="m.business">
      <!-- angka rupiah panjang: 2 kolom dari tablet ke atas agar tidak terpotong di desktop -->
      <div class="grid gap-3 sm:grid-cols-2 sm:gap-4">
        <StatTile v-for="k in businessKpis" :key="k.key" :icon="k.icon" :label="k.label" :value="k.value" :sub="k.sub" :delta="k.delta ?? null" />
      </div>
      <AppPanel title="Pendapatan 30 hari">
        <template #actions><AppButton variant="ghost" to="/finance">Keuangan</AppButton></template>
        <TimeBars :series="m.business.series" label="Pendapatan lunas per hari" :format="rupiah" :height="160" />
      </AppPanel>
    </template>
  </template>
</template>
