<script setup>
// Masa aktif workspace perusahaan: mulai → akhir periode, status, sisa hari, batas tenggang (data dari Subscription::activePeriod).
import { computed } from 'vue'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import { date } from '@/lib/format'

const props = defineProps({ period: { type: Object, default: null } })
const TONE = { trialing: 'info', active: 'ok', past_due: 'warn', expired: 'danger', cancelled: 'muted' }
const left = computed(() => {
  const p = props.period
  if (!p || p.status === 'expired') return null
  if (p.status === 'past_due') return `Tenggang ${p.days_left} hari lagi (s/d ${date(p.grace_until)})`
  return p.days_left <= 1 ? 'Berakhir besok' : `${p.days_left} hari lagi`
})
</script>

<template>
  <p v-if="!period" class="text-sm text-muted">Belum berlangganan.</p>
  <div v-else class="grid gap-1.5 text-sm">
    <div class="flex flex-wrap items-center gap-2">
      <AppBadge :tone="TONE[period.status] ?? 'muted'">{{ period.label }}</AppBadge>
      <span v-if="left" :class="period.days_left <= 7 ? 'font-medium text-warn' : 'text-muted'">{{ left }}</span>
    </div>
    <p class="font-mono">{{ date(period.start) }} → {{ date(period.end) }}</p>
  </div>
</template>
