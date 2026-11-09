<script setup>
// Tabel kartu server-side yang dipakai halaman Kartu, detail pengguna, workspace, dan batch.
// `list` = hasil usePagedList; kolom opsional lewat `show`.
import { computed } from 'vue'
import { BadgeCheck, Check, Minus } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import { dateTime } from '@/lib/format'

const props = defineProps({
  list: { type: Object, required: true },
  show: { type: Array, default: () => ['holder', 'batch'] }, // + 'sequence', 'progress', 'activate'
  emptyText: { type: String, default: 'Tidak ada kartu yang cocok.' },
})

const columns = computed(() => [
  props.show.includes('sequence') && { key: 'sequence', label: '#', sortable: true },
  { key: 'token', label: 'Token', sortable: true },
  { key: 'short_code', label: 'Kode', sortable: true },
  { key: 'status', label: 'Status', sortable: true },
  props.show.includes('holder') && { key: 'holder', label: 'Pemegang' },
  props.show.includes('batch') && { key: 'batch', label: 'Batch' },
  props.show.includes('progress') && { key: 'encoded', label: 'Encode' },
  props.show.includes('progress') && { key: 'qc', label: 'QC' },
  { key: 'last_tap_at', label: 'Tap terakhir', sortable: true },
  props.show.includes('activate') && { key: 'activate', label: '' },
].filter(Boolean))
</script>

<template>
  <DataTable
    dense row-key="token" :columns="columns" :empty-text="emptyText"
    :rows="list.rows.value" :meta="list.meta.value" :sort="list.sort.value" :loading="list.loading.value"
    @sort="list.toggleSort" @page="list.goto" @per-page="list.setPerPage"
  >
    <template #cell-sequence="{ row }"><span class="font-mono">{{ row.sequence }}</span></template>
    <template #cell-token="{ row }"><RouterLink :to="`/cards/${row.token}`" class="font-mono">{{ row.token }}</RouterLink></template>
    <template #cell-short_code="{ row }"><span class="font-mono">{{ row.short_code }}</span></template>
    <template #cell-status="{ row }"><AppBadge :tone="row.tone">{{ row.status_label }}</AppBadge></template>
    <template #cell-holder="{ row }">
      <template v-if="row.holder">{{ row.holder.email }}</template>
      <span v-else-if="row.assigned" class="text-muted" title="Pemilik ditandai, kartu belum diaktifkan">{{ row.assigned.email }} · menunggu aktivasi</span>
      <template v-else>—</template>
    </template>
    <template #cell-batch="{ row }"><span class="font-mono">{{ row.batch?.code }} #{{ row.sequence }}</span></template>
    <template #cell-encoded="{ row }"><component :is="row.encoded ? Check : Minus" :class="['size-4', row.encoded ? 'text-ok' : 'text-muted']" :aria-label="row.encoded ? 'Sudah' : 'Belum'" /></template>
    <template #cell-qc="{ row }"><component :is="row.qc ? Check : Minus" :class="['size-4', row.qc ? 'text-ok' : 'text-muted']" :aria-label="row.qc ? 'Lolos' : 'Belum'" /></template>
    <template #cell-last_tap_at="{ row }"><span class="font-mono">{{ dateTime(row.last_tap_at) }}</span></template>
    <!-- kartu lolos QC & belum dipakai → langsung ke Aktivasi kartu dengan kartu ini terpilih -->
    <template #cell-activate="{ row }">
      <RouterLink v-if="row.activatable" :to="{ path: '/aktivasi-kartu', query: { card: row.token } }" class="inline-flex min-h-9 items-center gap-1.5 text-sm font-semibold whitespace-nowrap">
        <BadgeCheck class="size-4" aria-hidden="true" /> Aktivasi
      </RouterLink>
    </template>
  </DataTable>
</template>
