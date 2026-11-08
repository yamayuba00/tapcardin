<script setup>
// status.{base} — halaman status publik: kondisi layanan saat ini, uptime 90 hari per layanan, info gangguan &
// maintenance. Realtime lewat Reverb (channel publik `status`) dengan cadangan muat ulang tiap 60 detik.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { CircleAlert, CircleCheck, CircleX, Wrench } from 'lucide-vue-next'
import BrandLogo from '@/components/common/BrandLogo/index.vue'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import UptimeBars from '@/components/uptime/UptimeBars/index.vue'
import { publicApi } from '@/api/public'
import { config } from '@/config'
import { connectStatusPage } from '@/lib/realtime'
import { dateTime, relative } from '@/lib/format'

const data = ref(null)
const failed = ref(false)
const live = ref(false)
const now = ref(new Date())

const OVERALL = {
  operational: { icon: CircleCheck, text: 'Semua layanan berjalan normal', cls: 'border-ok text-ok' },
  minor: { icon: CircleAlert, text: 'Sebagian layanan mengalami gangguan', cls: 'border-warn text-warn' },
  major: { icon: CircleX, text: 'Terjadi gangguan besar', cls: 'border-danger text-danger' },
}
const NOW = { up: ['ok', 'Normal'], degraded: ['warn', 'Terganggu'], down: ['danger', 'Gangguan'], unknown: ['muted', 'Belum ada data'] }
const fmtDay = (d) => new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(new Date(d + 'T00:00:00Z'))
const pct = (v) => (v == null ? '—' : `${Number(v).toLocaleString('id-ID', { maximumFractionDigits: 2 })}%`)
const dayTone = (d) => (d.uptime == null ? 'none' : d.uptime >= 99.9 ? 'ok' : d.uptime >= 95 ? 'warn' : 'danger')
const bars = (m) => m.days.map((d) => ({
  key: d.date, tone: dayTone(d),
  label: d.uptime == null ? `${fmtDay(d.date)}\nTidak ada data` : `${fmtDay(d.date)}\nUptime ${pct(d.uptime)}${d.down_checks ? ` · ${d.down_checks} cek gagal` : ' · tanpa gangguan'}`,
}))
const overall = computed(() => OVERALL[data.value?.overall ?? 'operational'])

async function load() {
  try {
    data.value = (await publicApi.status()).data
    failed.value = false
  } catch {
    failed.value = true
  }
}
let disconnect = null
let poll = null
const tick = setInterval(() => (now.value = new Date()), 15000)
onMounted(async () => {
  await load()
  poll = setInterval(() => !live.value && load(), 60000)
  if (config.realtime) {
    disconnect = await connectStatusPage(config.realtime, load, (s) => (live.value = s === 'connected')).catch(() => null)
  }
})
onBeforeUnmount(() => { disconnect?.(); clearInterval(poll); clearInterval(tick) })
</script>

<template>
  <main class="mx-auto grid w-full max-w-3xl gap-6 px-4 py-8 sm:py-12">
    <header class="flex flex-wrap items-center justify-between gap-3">
      <a :href="config.marketingUrl || '/'" class="no-underline" aria-label="TapCard"><BrandLogo /></a>
      <span class="label">Status layanan</span>
    </header>

    <AppSkeleton v-if="!data && !failed" class="!h-64" />
    <p v-else-if="!data" class="rounded-md border border-line bg-surface p-4 text-sm" role="alert">Status belum bisa dimuat. Coba muat ulang halaman sebentar lagi.</p>
    <template v-else>
      <section :class="['flex items-center gap-3 rounded-md border-2 bg-surface p-5', overall.cls]" role="status">
        <component :is="overall.icon" class="size-7 shrink-0" aria-hidden="true" />
        <h1 class="text-xl font-semibold text-fg sm:text-2xl">{{ overall.text }}</h1>
      </section>

      <!-- gangguan & maintenance yang sedang berjalan / terjadwal -->
      <section v-for="i in data.incidents" :key="i.id" class="grid gap-3 rounded-md border border-line bg-surface p-5">
        <div class="flex flex-wrap items-center gap-2">
          <component :is="i.kind === 'maintenance' ? Wrench : CircleAlert" class="size-5" :class="i.kind === 'maintenance' ? 'text-info' : i.impact === 'major' ? 'text-danger' : 'text-warn'" aria-hidden="true" />
          <h2 class="text-lg font-semibold">{{ i.title }}</h2>
          <AppBadge :tone="i.kind === 'maintenance' ? 'info' : i.impact === 'major' ? 'danger' : 'warn'">{{ i.status_label }}</AppBadge>
        </div>
        <p v-if="i.kind === 'maintenance'" class="text-sm text-muted">{{ dateTime(i.starts_at) }}{{ i.ends_at ? ` – ${dateTime(i.ends_at)}` : '' }} WIB</p>
        <ol class="grid gap-3 border-l-2 border-line pl-4">
          <li v-for="(u, k) in i.updates" :key="k" class="grid gap-1">
            <p class="text-sm"><strong>{{ u.status_label }}</strong> <span class="text-muted">· {{ dateTime(u.at) }}</span></p>
            <p class="text-sm whitespace-pre-line">{{ u.body }}</p>
          </li>
        </ol>
      </section>

      <section class="grid gap-1 rounded-md border border-line bg-surface" aria-labelledby="layanan">
        <div class="flex flex-wrap items-center justify-between gap-2 px-5 pt-5">
          <h2 id="layanan" class="text-lg font-semibold">Layanan</h2>
          <span class="text-sm text-muted">Uptime 90 hari terakhir</span>
        </div>
        <p v-if="!data.monitors.length" class="px-5 pb-5 text-sm text-muted">Belum ada layanan yang dipantau.</p>
        <ul>
          <li v-for="m in data.monitors" :key="m.id" class="grid gap-3 border-b border-line px-5 py-5 last:border-b-0">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <span class="font-semibold">{{ m.name }}</span>
              <span class="flex items-center gap-3 text-sm"><span class="text-muted">{{ pct(m.uptime) }}</span><AppBadge :tone="NOW[m.status][0]" dot>{{ NOW[m.status][1] }}</AppBadge></span>
            </div>
            <UptimeBars :items="bars(m)" :label="`Uptime harian ${m.name}, 90 hari`" />
            <div class="flex justify-between text-xs text-muted"><span>90 hari lalu</span><span>Hari ini</span></div>
          </li>
        </ul>
        <!-- legenda: makna warna bar -->
        <ul class="flex flex-wrap gap-x-4 gap-y-1 border-t border-line px-5 py-3 text-xs text-muted" aria-label="Keterangan warna">
          <li class="flex items-center gap-1.5"><span class="size-2.5 rounded-[2px] bg-ok"></span> ≥ 99,9%</li>
          <li class="flex items-center gap-1.5"><span class="size-2.5 rounded-[2px] bg-warn"></span> 95–99,9%</li>
          <li class="flex items-center gap-1.5"><span class="size-2.5 rounded-[2px] bg-danger"></span> &lt; 95%</li>
          <li class="flex items-center gap-1.5"><span class="size-2.5 rounded-[2px] bg-line"></span> Tidak ada data</li>
        </ul>
      </section>

      <section v-if="data.history.length" class="grid gap-3">
        <h2 class="text-lg font-semibold">Riwayat 14 hari</h2>
        <article v-for="i in data.history" :key="i.id" class="grid gap-1 rounded-md border border-line bg-surface p-4">
          <p class="flex flex-wrap items-center gap-2"><strong>{{ i.title }}</strong> <AppBadge tone="ok">{{ i.status_label }}</AppBadge></p>
          <p class="text-sm text-muted">{{ dateTime(i.starts_at) }} – {{ dateTime(i.resolved_at) }} WIB</p>
          <p class="text-sm whitespace-pre-line">{{ i.updates[0]?.body }}</p>
        </article>
      </section>

      <p class="text-center text-xs text-muted">
        <span v-if="live">● Diperbarui otomatis secara langsung</span><span v-else>Diperbarui otomatis tiap menit</span>
        · data {{ relative(data.updated_at, now) }}
      </p>
    </template>
  </main>
</template>
