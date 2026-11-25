<script setup>
// Laporan kartu untuk owner/admin: masa berlaku semua kartu dan rekap tap per anggota (30 hari).
import { computed, ref } from 'vue'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppEmpty from '@/components/ui/AppEmpty/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import AppTable from '@/components/ui/AppTable/index.vue'
import KpiCard from '@/components/common/KpiCard/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { reportsApi } from '@/api/workspace'
import { useAsync } from '@/composables/useAsync'
import { date, num, relative } from '@/lib/format'

const { data: cards, meta: report } = useAsync(() => reportsApi.cards())
const view = ref('validity')
const filter = ref('all')

const STATES = {
  active: { label: 'Aktif', tone: 'ok' },
  grace: { label: 'Tenggang', tone: 'warn' },
  expired: { label: 'Nonaktif', tone: 'danger' },
  none: { label: 'Belum aktif', tone: 'muted' },
  digital: { label: 'Digital', tone: 'info' },
}
const VIEWS = [
  { value: 'validity', label: 'Masa berlaku' },
  { value: 'members', label: 'Rekap per anggota' },
]
const FILTERS = computed(() => [
  { value: 'all', label: `Semua (${report.value?.totals.cards ?? 0})` },
  { value: 'expiring', label: `Akan berakhir (${report.value?.totals.expiring ?? 0})` },
  { value: 'grace', label: `Tenggang (${report.value?.totals.grace ?? 0})` },
  { value: 'expired', label: `Nonaktif (${report.value?.totals.expired ?? 0})` },
  { value: 'active', label: `Aktif (${report.value?.totals.active ?? 0})` },
  { value: 'digital', label: `Digital (${report.value?.totals.digital ?? 0})` },
])
const rows = computed(() => {
  const all = cards.value ?? []
  if (filter.value === 'all') return all
  if (filter.value === 'expiring') return all.filter((r) => r.expiring)
  return all.filter((r) => r.state === filter.value)
})
const members = computed(() => report.value?.members ?? [])
const holderName = (h) => (h ? h.name || h.email : 'Belum dipegang')
const daysLabel = (r) => {
  if (r.days_left === null) return '—'
  if (r.days_left < 0) return `${-r.days_left} hari lalu`
  return `${r.days_left} hari`
}
const windowDays = computed(() => report.value?.window_days ?? 30)
</script>

<template>
  <PageHeader eyebrow="Perusahaan" title="Laporan kartu" />
  <AppSkeleton v-if="!report" class="!h-96" />

  <template v-else>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
      <KpiCard label="Kartu" :value="num(report.totals.cards)" />
      <KpiCard label="Aktif" :value="num(report.totals.active)" />
      <KpiCard label="Akan berakhir" :value="num(report.totals.expiring)" :hint="`≤ ${report.expiring_days} hari`" />
      <KpiCard label="Tenggang" :value="num(report.totals.grace)" hint="lewat masa, belum nonaktif" />
      <KpiCard label="Nonaktif" :value="num(report.totals.expired)" />
      <KpiCard label="Tap" :value="num(report.totals.taps_30)" :hint="`${windowDays} hari terakhir`" />
    </div>

    <AppSegmented v-model="view" label="Tampilan" :options="VIEWS" />

    <AppPanel v-if="view === 'validity'" title="Masa berlaku kartu">
      <div class="grid gap-4">
        <p class="text-sm text-muted">Diurutkan dari yang paling dekat jatuh tempo. Diperbarui {{ relative(report.generated_at) }}.</p>
        <AppSegmented v-model="filter" label="Filter" :options="FILTERS" />
        <AppEmpty v-if="!rows.length" text="Tidak ada kartu pada filter ini." />
        <AppTable v-else dense>
          <thead>
            <tr>
              <th>Kartu</th><th>Pemegang</th><th>Status</th><th>Berlaku sampai</th><th>Sisa</th><th>Tap {{ windowDays }} hari</th><th>Tap terakhir</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in rows" :key="r.token">
              <td>
                <RouterLink :to="`/cards/${r.token}`" class="font-medium underline-offset-4 hover:underline">{{ r.label }}</RouterLink>
                <span class="block font-mono text-xs text-muted">{{ r.short_code }}</span>
              </td>
              <td>{{ holderName(r.holder) }}</td>
              <td><AppBadge :tone="STATES[r.state].tone">{{ STATES[r.state].label }}</AppBadge></td>
              <td class="font-mono">
                <template v-if="r.valid_until">{{ date(r.valid_until) }}</template>
                <span v-else class="text-muted">—</span>
                <span v-if="r.expiring" class="block text-xs text-warn">akan berakhir</span>
              </td>
              <td class="font-mono" :class="r.state === 'expired' ? 'text-danger' : r.state === 'grace' ? 'text-warn' : ''">{{ daysLabel(r) }}</td>
              <td class="font-mono">{{ num(r.taps_30) }}</td>
              <td class="text-muted">{{ r.last_tap_at ? relative(r.last_tap_at) : 'belum pernah' }}</td>
            </tr>
          </tbody>
        </AppTable>
      </div>
    </AppPanel>

    <AppPanel v-else title="Rekap per anggota">
      <p class="mb-4 text-sm text-muted">Jumlah kartu, status masa berlaku, dan tap {{ windowDays }} hari terakhir per pemegang kartu.</p>
      <AppEmpty v-if="!members.length" text="Belum ada kartu di workspace ini." />
      <AppTable v-else dense>
        <thead>
          <tr>
            <th>Anggota</th><th>Kartu</th><th>Aktif</th><th>Tenggang / nonaktif</th><th>Akan berakhir</th><th>Tap {{ windowDays }} hari</th><th>Tap terakhir</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(m, i) in members" :key="i">
            <td>
              <span class="font-medium">{{ holderName(m.holder) }}</span>
              <span v-if="m.holder?.email" class="block text-xs text-muted">{{ m.holder.email }}</span>
            </td>
            <td class="font-mono">{{ num(m.cards) }}</td>
            <td class="font-mono">{{ num(m.active) }}</td>
            <td class="font-mono">{{ num(m.grace) }} / {{ num(m.expired) }}</td>
            <td class="font-mono" :class="m.expiring ? 'text-warn' : ''">{{ num(m.expiring) }}</td>
            <td class="font-mono">{{ num(m.taps_30) }}</td>
            <td class="text-muted">{{ m.last_tap_at ? relative(m.last_tap_at) : 'belum pernah' }}</td>
          </tr>
        </tbody>
      </AppTable>
    </AppPanel>

  </template>
</template>
