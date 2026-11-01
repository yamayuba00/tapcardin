<script setup>
// U-08 / AN-01 — tap per hari (total vs unik), sumber, perangkat, kota, per kartu, klik tautan.
import { computed, ref, watch } from 'vue'
import AppMeter from '@/components/ui/AppMeter/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import KpiCard from '@/components/common/KpiCard/index.vue'
import MiniChart from '@/components/common/MiniChart/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { analyticsApi } from '@/api/workspace'
import { num, sourceLabel } from '@/lib/format'

const DEVICES = { ios: 'iPhone / iPad', android: 'Android', desktop: 'Desktop', other: 'Lainnya' }
const days = ref(30)
const data = ref(null)
const retention = ref(null)

const options = computed(() => [7, 30, 90, 365].filter((d) => !retention.value || d <= retention.value).map((d) => ({ value: d, label: `${d} hari` })))
const pct = (n, total) => (total ? Math.round((n / total) * 100) : 0)
const groups = computed(() => [
  { title: 'Sumber', key: 'sources', fmt: sourceLabel },
  { title: 'Perangkat', key: 'devices', fmt: (k) => DEVICES[k] || k },
  { title: 'Kota', key: 'cities', fmt: (k) => k },
])

async function load() {
  data.value = null
  data.value = (await analyticsApi.cards(days.value)).data
  retention.value = data.value.retention_days
}
watch(days, load, { immediate: true })
</script>

<template>
  <PageHeader eyebrow="Analitik" title="Analitik kartu">
    <AppSegmented v-model="days" :options="options" label="Rentang waktu" class="max-w-full" />
  </PageHeader>
  <p v-if="retention" class="text-sm text-muted">Paket Anda menyimpan data analitik {{ retention }} hari.</p>

  <AppSkeleton v-if="!data" class="!h-56" />
  <template v-else>
    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
      <KpiCard label="Tap" :value="num(data.totals.taps)" />
      <KpiCard label="Pengunjung unik" :value="num(data.totals.visitors)" />
      <KpiCard label="Simpan kontak" :value="num(data.totals.saved)" :hint="`${pct(data.totals.saved, data.totals.taps)}% dari tap`" />
      <KpiCard label="Koneksi baru" :value="num(data.totals.connections)" />
    </div>

    <AppPanel title="Tap per hari">
      <template #actions>
        <span class="flex items-center gap-2 text-sm text-muted">
          <span class="size-3 rounded-sm bg-accent"></span>Total
          <span class="size-3 rounded-sm border border-muted"></span>Unik
        </span>
      </template>
      <MiniChart :series="data.series" second-key="unique" label="Tap per hari, total dan unik" />
    </AppPanel>

    <div class="grid gap-4 md:grid-cols-2">
      <AppPanel v-for="g in groups" :key="g.key" :title="g.title">
        <div class="grid gap-4">
          <AppMeter v-for="(n, k) in data[g.key]" :key="k" :label="g.fmt(k)" :value="pct(n, data.totals.taps)" plain>{{ num(n) }}</AppMeter>
          <p v-if="!Object.keys(data[g.key]).length" class="text-muted">Belum ada data.</p>
        </div>
      </AppPanel>
      <AppPanel title="Per kartu">
        <ul class="mb-6">
          <li v-for="c in data.cards" :key="c.token" class="flex justify-between border-b border-line py-3 last:border-b-0"><span>{{ c.label }}</span><span class="font-mono">{{ num(c.taps) }}</span></li>
          <li v-if="!data.cards.length" class="text-muted">Belum ada kartu aktif.</li>
        </ul>
        <h3 class="mb-2">Klik tautan</h3>
        <ul>
          <li v-for="(l, i) in data.links" :key="i" class="flex justify-between border-b border-line py-3 last:border-b-0"><span>{{ l.label }}</span><span class="font-mono">{{ num(l.clicks) }}</span></li>
          <li v-if="!data.links.length" class="text-muted">Belum ada klik.</li>
        </ul>
      </AppPanel>
    </div>
  </template>
</template>
