<script setup>
// U-01 / DBD §10.1 — KPI, grafik tap 30 hari, aktivitas terbaru realtime.
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { CircleAlert, CreditCard, Nfc, Share2, Users } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppEmpty from '@/components/ui/AppEmpty/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import KpiCard from '@/components/common/KpiCard/index.vue'
import MiniChart from '@/components/common/MiniChart/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { dashboardApi } from '@/api/workspace'
import { useAsync } from '@/composables/useAsync'
import { delta, num, relative } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import { useNotificationStore } from '@/stores/notifications'

const auth = useAuthStore()
const notifications = useNotificationStore()
const { data, error, reload } = useAsync(() => dashboardApi.summary())
const now = ref(new Date())
let timer
let unsubscribe

onMounted(() => {
  timer = setInterval(() => (now.value = new Date()), 30000)
  unsubscribe = notifications.subscribe((type, d) => {
    if (!data.value) return
    if (type === 'tap') {
      data.value.recent.unshift({ type: 'tap', at: d.at, title: 'Profil Anda dibuka', meta: [d.city].filter(Boolean) })
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

const tapDelta = computed(() => (data.value ? delta(data.value.kpi.taps_7, data.value.kpi.taps_prev_7) : null))
const empty = computed(() => data.value && data.value.kpi.taps_30 === 0 && data.value.recent.length === 0)
</script>

<template>
  <PageHeader eyebrow="Ringkasan" title="Beranda">
    <AppButton variant="secondary" to="/cards"><CreditCard class="size-5" /> Kartu saya</AppButton>
    <AppButton v-if="data?.share_token" :to="`/cards/${data.share_token}/share`"><Share2 class="size-5" /> Bagikan kartu</AppButton>
  </PageHeader>

  <div v-if="error" class="flex items-center gap-3 rounded-md border border-line-strong bg-surface-2 px-4 py-3 text-sm" role="alert">
    <CircleAlert class="size-5 text-danger" /><span class="flex-1">{{ error.message }}</span>
    <AppButton variant="ghost" @click="reload">Muat ulang</AppButton>
  </div>

  <div v-if="!data && !error" class="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-4" aria-busy="true">
    <AppSkeleton v-for="i in 4" :key="i" class="!h-28" />
  </div>

  <template v-if="data">
    <div class="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-4">
      <KpiCard label="Tap 7 hari" :value="num(data.kpi.taps_7)" :delta="tapDelta" hint="Minggu lalu belum ada tap" />
      <KpiCard label="Pengunjung unik 30 hari" :value="num(data.kpi.visitors_30)" />
      <KpiCard label="Simpan kontak" :value="num(data.kpi.saved_30)" :hint="data.kpi.save_rate !== null ? `${data.kpi.save_rate}% dari tap` : 'Belum ada tap'" />
      <KpiCard :label="`${auth.contactLabel} 30 hari`" :value="num(data.kpi.connections_30)" />
    </div>

    <AppEmpty v-if="empty" text="Belum ada tap. Coba tempel kartu ke ponsel Anda sendiri.">
      <AppButton to="/cards">Lihat kartu</AppButton>
    </AppEmpty>

    <div v-else class="grid gap-4 md:grid-cols-2">
      <AppPanel title="Tap 30 hari">
        <template #actions><span class="text-sm text-muted">{{ num(data.kpi.taps_30) }} total</span></template>
        <MiniChart :series="data.series" label="Tap per hari selama 30 hari" />
      </AppPanel>
      <AppPanel title="Aktivitas terbaru">
        <template #actions><AppButton variant="ghost" to="/connections">{{ auth.contactLabel }}</AppButton></template>
        <ul>
          <li v-for="(r, i) in data.recent" :key="i" class="flex min-h-14 items-center gap-3 border-b border-line py-3 last:border-b-0">
            <span class="grid size-10 flex-none place-items-center rounded-full bg-surface-2 text-muted">
              <component :is="r.type === 'tap' ? Nfc : Users" class="size-5" aria-hidden="true" />
            </span>
            <div class="min-w-0 flex-1">
              <RouterLink v-if="r.id" :to="`/connections/${r.id}`" class="block truncate font-medium !text-fg">{{ r.title }}</RouterLink>
              <span v-else class="block truncate font-medium">{{ r.title }}</span>
              <span class="text-sm text-muted">{{ [relative(r.at, now), ...Object.values(r.meta ?? {})].join(' · ') }}</span>
            </div>
          </li>
          <li v-if="!data.recent.length" class="py-3 text-muted">Belum ada aktivitas.</li>
        </ul>
      </AppPanel>
    </div>

    <AppPanel v-if="data.cards.list.length" title="Kartu">
      <template #actions><span class="text-sm text-muted">{{ data.cards.active }} aktif</span></template>
      <ul>
        <li v-for="c in data.cards.list" :key="c.token" class="flex min-h-14 items-center gap-3 border-b border-line py-3 last:border-b-0">
          <span class="grid size-10 flex-none place-items-center rounded-full bg-surface-2 text-muted"><CreditCard class="size-5" aria-hidden="true" /></span>
          <RouterLink :to="`/cards/${c.token}`" class="min-w-0 flex-1 no-underline">
            <span class="block truncate font-medium text-fg">{{ c.label || 'Kartu ' + c.short_code }}</span>
            <span class="text-sm text-muted">{{ c.profile?.label }} · tap terakhir {{ relative(c.last_tap_at, now) }}</span>
          </RouterLink>
          <AppBadge :tone="c.tone">{{ c.status_label }}</AppBadge>
        </li>
      </ul>
    </AppPanel>
  </template>
</template>
