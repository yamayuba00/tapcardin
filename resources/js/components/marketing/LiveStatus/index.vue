<script setup>
// Status layanan langsung di landing page (GET /api/status di domain utama — data sama dengan status.{base}).
// Diperbarui tiap menit selama halaman terbuka; tautan ke halaman status lengkap.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { CircleAlert, CircleCheck, CircleX, ExternalLink } from 'lucide-vue-next'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import UptimeBars from '@/components/uptime/UptimeBars/index.vue'
import { marketingApi } from '@/api/public'
import { config } from '@/config'

const data = ref(null)
const failed = ref(false)
const OVERALL = {
  operational: { icon: CircleCheck, text: 'Semua layanan berjalan normal', cls: 'text-ok' },
  minor: { icon: CircleAlert, text: 'Sebagian layanan sedang terganggu', cls: 'text-warn' },
  major: { icon: CircleX, text: 'Sedang ada gangguan', cls: 'text-danger' },
}
const overall = computed(() => OVERALL[data.value?.overall ?? 'operational'])
const pct = (v) => (v == null ? '—' : `${Number(v).toLocaleString('id-ID', { maximumFractionDigits: 2 })}%`)
const fmtDay = (d) => new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(new Date(d + 'T00:00:00Z'))
// 30 hari terakhir saja agar ringkas
const bars = (m) => m.days.slice(-30).map((d) => ({
  key: d.date,
  tone: d.uptime == null ? 'none' : d.uptime >= 99.9 ? 'ok' : d.uptime >= 95 ? 'warn' : 'danger',
  label: d.uptime == null ? `${fmtDay(d.date)}\nTidak ada data` : `${fmtDay(d.date)}\nUptime ${pct(d.uptime)}`,
}))

async function load() {
  try {
    data.value = (await marketingApi.status()).data
    failed.value = false
  } catch {
    failed.value = true // landing tetap utuh; widget menunjuk ke halaman status
  }
}
let timer = null
onMounted(() => { load(); timer = setInterval(load, 60000) })
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <div class="grid gap-4 rounded-lg border border-line bg-surface p-5 sm:p-6">
    <p v-if="!data && failed" class="flex flex-wrap items-center justify-between gap-3 text-sm text-muted">
      Status langsung belum bisa dimuat.
      <a :href="config.statusUrl" class="inline-flex min-h-11 items-center gap-1.5 font-semibold text-accent-text underline">Buka halaman status <ExternalLink class="size-4" /></a>
    </p>
    <AppSkeleton v-else-if="!data" class="!h-40" />
    <template v-else>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="flex items-center gap-2 font-semibold" role="status">
          <component :is="overall.icon" class="size-5" :class="overall.cls" aria-hidden="true" /> {{ overall.text }}
        </p>
        <a :href="config.statusUrl" class="inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-accent-text underline">Halaman status <ExternalLink class="size-4" /></a>
      </div>
      <ul class="grid gap-4">
        <li v-for="m in data.monitors" :key="m.id" class="grid gap-2">
          <div class="flex items-center justify-between gap-2 text-sm">
            <span class="font-medium">{{ m.name }}</span>
            <span class="text-muted">{{ pct(m.uptime) }} uptime</span>
          </div>
          <UptimeBars :items="bars(m)" :label="`Uptime harian ${m.name}, 30 hari`" height="h-6" />
        </li>
      </ul>
      <p class="text-xs text-muted">30 hari terakhir · dipantau otomatis setiap menit</p>
    </template>
  </div>
</template>
