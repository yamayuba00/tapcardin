<script setup>
// PL-01 — pengguna, kartu per status, rasio aktivasi ≤ 7 hari, tap, batch berjalan; MRR & pendapatan untuk pemilik.
import { computed } from 'vue'
import { CopyPlus } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import KpiCard from '@/components/common/KpiCard/index.vue'
import MiniChart from '@/components/common/MiniChart/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import TimeBars from '@/components/charts/TimeBars/index.vue'
import { adminApi } from '@/api/admin'
import { useAsync } from '@/composables/useAsync'
import { CARD_STATUS, delta, num, rupiah } from '@/lib/format'

const { data: m } = useAsync(() => adminApi.dashboard())
const total = (cards) => Object.values(cards).reduce((a, b) => a + b, 0)
// antrean yang menunggu orang ini (null = bukan wewenangnya; 0 = kosong)
const todo = computed(() => {
  const a = m.value?.attention ?? {}
  return [
    { n: a.payment_reviews, label: 'Pembayaran bermasalah perlu ditinjau', to: '/approvals?tab=reviews' },
    { n: a.approvals, label: 'Permintaan keuangan menunggu persetujuan', to: '/approvals' },
    { n: a.refunds_decide, label: 'Refund menunggu keputusan', to: '/approvals?tab=refunds' },
    { n: a.refunds_payout, label: 'Refund disetujui, menunggu pencatatan transfer', to: '/approvals?tab=refunds' },
    { n: a.orders_review, label: 'Pesanan bertanda risiko menunggu tinjauan', to: '/orders?status=paid' },
  ].filter((x) => x.n)
})
</script>

<template>
  <PageHeader eyebrow="Panel pemilik" title="Dashboard" />
  <AppSkeleton v-if="!m" class="!h-28" />
  <template v-else>
    <div class="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-4">
      <KpiCard label="Pengguna" :value="num(m.users)" :hint="`+${m.users_7} dalam 7 hari`" />
      <KpiCard label="Kartu aktif" :value="num(m.cards.active)" :hint="`dari ${num(total(m.cards))} kartu`" />
      <KpiCard label="Aktivasi ≤ 7 hari" :value="m.activation_rate !== null ? `${m.activation_rate}%` : '—'" hint="Target ≥ 85%" />
      <KpiCard label="Tap hari ini" :value="num(m.taps_today)" :hint="`${num(m.taps_30)} dalam 30 hari`" />
    </div>

    <template v-if="m.business">
      <div class="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-4">
        <KpiCard label="MRR" :value="rupiah(m.business.mrr)" :hint="`ARR ${rupiah(m.business.arr)}`" />
        <KpiCard label="Pendapatan bulan ini" :value="rupiah(m.business.revenue_month)" :delta="delta(m.business.revenue_month, m.business.revenue_prev_month)" />
        <KpiCard label="Langganan aktif" :value="num(m.business.active_subscriptions)" :hint="m.business.past_due ? `${m.business.past_due} menunggak` : 'Tidak ada tunggakan'" />
        <KpiCard label="Tagihan terbuka" :value="num(m.business.open_invoices)" :hint="rupiah(m.business.open_amount)" />
      </div>
      <AppPanel title="Pendapatan 30 hari">
        <template #actions><AppButton variant="ghost" to="/finance">Keuangan</AppButton></template>
        <TimeBars :series="m.business.series" label="Pendapatan lunas per hari" :format="rupiah" :height="160" />
      </AppPanel>
    </template>

    <div class="grid gap-4 md:grid-cols-2">
      <AppPanel title="Tap 30 hari"><MiniChart :series="m.series" label="Tap seluruh platform 30 hari" /></AppPanel>
      <AppPanel title="Kartu per status">
        <ul>
          <li v-for="(n, s) in m.cards" :key="s" class="flex items-center justify-between border-b border-line py-3 last:border-b-0">
            <AppBadge :tone="CARD_STATUS[s].tone">{{ CARD_STATUS[s].label }}</AppBadge><span class="font-mono">{{ num(n) }}</span>
          </li>
        </ul>
      </AppPanel>
    </div>

    <AppPanel title="Batch produksi berjalan">
      <template #actions><AppButton variant="ghost" to="/batches">Semua batch</AppButton></template>
      <ul>
        <li v-for="b in m.batches_open" :key="b.id" class="flex items-center justify-between border-b border-line py-3 last:border-b-0">
          <RouterLink :to="`/batches/${b.id}`" class="no-underline">
            <span class="block font-medium text-fg">{{ b.code }}</span>
            <span class="text-sm text-muted">{{ b.quantity }} kartu · {{ b.chip.toUpperCase() }}</span>
          </RouterLink>
          <AppBadge tone="info">{{ b.status_label }}</AppBadge>
        </li>
        <li v-if="!m.batches_open.length" class="text-muted">Tidak ada batch yang sedang berjalan.</li>
      </ul>
    </AppPanel>

    <AppPanel v-if="todo.length" title="Perlu tindakan Anda">
      <ul>
        <li v-for="t in todo" :key="t.label" class="flex items-center justify-between gap-3 border-b border-line py-3 last:border-b-0">
          <RouterLink :to="t.to" class="font-medium no-underline">{{ t.label }}</RouterLink>
          <AppBadge tone="warn">{{ num(t.n) }}</AppBadge>
        </li>
      </ul>
    </AppPanel>

    <p v-if="m.replacements_pending" class="flex items-center gap-3 rounded-md border border-line-strong bg-surface-2 px-4 py-3 text-sm">
      <CopyPlus class="size-5 text-warn" />
      <span>{{ num(m.replacements_pending) }} kartu hilang menunggu batch pengganti.</span>
      <RouterLink to="/cards?replacement=1" class="ml-auto font-semibold">Tinjau</RouterLink>
    </p>

  </template>
</template>
