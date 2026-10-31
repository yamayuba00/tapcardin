<script setup>
// Satu widget dashboard lead. Data sudah dihitung server (LeadDashboardController); di sini hanya tampilan.
// Mode atur: geser urutan, ubah lebar (1/2 kolom), lepas widget.
import { computed } from 'vue'
import { ArrowDown, ArrowLeft, ArrowRight, ArrowUp, CircleAlert, Clock, Columns2, Square, X } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import BarList from '@/components/charts/BarList/index.vue'
import StageBadge from '@/components/common/StageBadge/index.vue'
import TimeBars from '@/components/charts/TimeBars/index.vue'
import { num, relative } from '@/lib/format'

const props = defineProps({
  meta: { type: Object, required: true }, // { id, title, kind }
  data: { type: Object, default: null },
  size: { type: Number, default: 1 },
  days: { type: Number, required: true },
  editing: Boolean,
  first: Boolean,
  last: Boolean,
})
const emit = defineEmits(['move', 'resize', 'remove'])
const rupiah = (n) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n ?? 0)
const isKpi = computed(() => props.meta.kind === 'kpi')
</script>

<template>
  <section :class="['grid content-start gap-3 rounded-md border bg-surface p-5', editing ? 'border-dashed border-line-strong' : 'border-line', size === 2 ? 'md:col-span-2' : '']" :aria-label="meta.title">
    <header class="flex items-center justify-between gap-2">
      <h2 :class="isKpi ? 'label' : 'text-base font-semibold'">{{ meta.title }}</h2>
      <div v-if="editing" class="flex items-center gap-0.5">
        <AppButton variant="ghost" icon :aria-label="`Geser ${meta.title} ke depan`" :disabled="first" @click="emit('move', -1)"><component :is="isKpi ? ArrowLeft : ArrowUp" class="size-4" /></AppButton>
        <AppButton variant="ghost" icon :aria-label="`Geser ${meta.title} ke belakang`" :disabled="last" @click="emit('move', 1)"><component :is="isKpi ? ArrowRight : ArrowDown" class="size-4" /></AppButton>
        <AppButton variant="ghost" icon :aria-label="size === 2 ? 'Jadikan 1 kolom' : 'Jadikan 2 kolom'" :title="size === 2 ? 'Lebar: 2 kolom' : 'Lebar: 1 kolom'" @click="emit('resize')"><component :is="size === 2 ? Columns2 : Square" class="size-4" /></AppButton>
        <AppButton variant="ghost" icon :aria-label="`Lepas ${meta.title}`" @click="emit('remove')"><X class="size-4" /></AppButton>
      </div>
    </header>

    <div v-if="!data" class="h-16 animate-skeleton rounded-sm bg-surface-2"></div>

    <!-- KPI -->
    <template v-else-if="meta.id === 'kpi_total'">
      <span class="num text-[32px] leading-none font-medium">{{ num(data.value) }}</span>
      <span class="text-sm text-muted">{{ data.hint }}</span>
    </template>
    <template v-else-if="meta.id === 'kpi_new'">
      <span class="num text-[32px] leading-none font-medium">{{ num(data.value) }}</span>
      <span v-if="data.delta !== null" :class="['inline-flex items-center gap-1 text-sm', data.delta >= 0 ? 'text-ok' : 'text-danger']">
        <component :is="data.delta >= 0 ? ArrowUp : ArrowDown" class="size-4" aria-hidden="true" />
        {{ Math.abs(data.delta) }}% dari {{ days }} hari sebelumnya ({{ num(data.previous) }})
      </span>
      <span v-else class="text-sm text-muted">{{ days }} hari terakhir</span>
    </template>
    <template v-else-if="meta.id === 'kpi_won_rate'">
      <span class="num text-[32px] leading-none font-medium">{{ data.value === null ? '—' : `${data.value}%` }}</span>
      <span class="text-sm text-muted">{{ data.won }} menang · {{ data.lost }} kalah dalam {{ days }} hari</span>
    </template>
    <template v-else-if="meta.id === 'kpi_followup'">
      <div class="flex items-end gap-6">
        <RouterLink to="/follow-up?due=overdue" class="grid gap-1 no-underline">
          <span :class="['num text-[32px] leading-none font-medium', data.overdue ? 'text-danger' : 'text-fg']">{{ num(data.overdue) }}</span>
          <span class="inline-flex items-center gap-1 text-sm text-muted"><CircleAlert class="size-4" aria-hidden="true" /> terlambat</span>
        </RouterLink>
        <RouterLink to="/follow-up?due=today" class="grid gap-1 no-underline">
          <span class="num text-[32px] leading-none font-medium text-fg">{{ num(data.today) }}</span>
          <span class="inline-flex items-center gap-1 text-sm text-muted"><Clock class="size-4" aria-hidden="true" /> hari ini</span>
        </RouterLink>
      </div>
    </template>
    <template v-else-if="meta.id === 'kpi_value'">
      <span class="num text-[28px] leading-none font-medium">{{ rupiah(data.value) }}</span>
      <span class="text-sm text-muted">Menang {{ rupiah(data.won) }} dalam {{ days }} hari</span>
    </template>

    <!-- grafik -->
    <TimeBars v-else-if="meta.kind === 'series'" :series="data.series" :label="`${meta.title}, ${days} hari terakhir`" />
    <BarList v-else-if="meta.kind === 'bars'" :items="data.bars" :label="meta.title" />

    <!-- daftar -->
    <ul v-else-if="meta.kind === 'list'" class="grid">
      <li v-for="c in data.rows" :key="c.id" class="border-b border-line last:border-b-0">
        <RouterLink :to="`/connections/${c.id}`" class="flex min-h-12 items-center gap-3 py-2 text-fg no-underline hover:bg-surface-2">
          <span class="min-w-0 flex-1">
            <span class="block truncate font-medium">{{ c.name }}</span>
            <span class="block truncate text-xs text-muted">{{ c.company || '—' }} · {{ meta.id === 'overdue_list' ? `jatuh tempo ${relative(c.next_followup_at)}` : relative(c.created_at) }}</span>
          </span>
          <StageBadge v-if="c.stage" :stage="c.stage" />
        </RouterLink>
      </li>
      <li v-if="!data.rows.length" class="py-6 text-center text-sm text-muted">{{ meta.id === 'overdue_list' ? 'Tidak ada follow-up terlambat.' : 'Belum ada lead.' }}</li>
    </ul>
  </section>
</template>
