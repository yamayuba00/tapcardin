<script setup>
// Daftar pesanan kartu & lanyard milik pengguna (atau seluruh workspace untuk pengelola tagihan).
import { Plus } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { ordersApi } from '@/api/workspace'
import { usePagedList } from '@/composables/usePagedList'
import { dateTime, rupiah } from '@/lib/format'
import { ORDER_STATUS } from '@/lib/orders'

const list = usePagedList(ordersApi.list, { status: '' }, { sort: '-created_at', perPage: 10 })
const COLUMNS = [
  { key: 'number', label: 'Pesanan', sortable: true }, { key: 'status', label: 'Status' },
  { key: 'total', label: 'Total', sortable: true, align: 'right' }, { key: 'created_at', label: 'Dibuat', sortable: true },
]
</script>

<template>
  <PageHeader eyebrow="Kartu" title="Pesanan">
    <AppButton to="/orders/new"><Plus class="size-5" /> Pesan kartu & lanyard</AppButton>
  </PageHeader>
  <DataTable
    :columns="COLUMNS" :rows="list.rows.value" :meta="list.meta.value" :sort="list.sort.value" :loading="list.loading.value"
    empty-text="Belum ada pesanan. Pesan kartu fisik dari desain Anda — kartu bisa langsung diaktifkan begitu tiba."
    @sort="list.toggleSort" @page="list.goto" @per-page="list.setPerPage"
  >
    <template #cell-number="{ row }">
      <RouterLink :to="`/orders/${row.id}`" class="font-mono">{{ row.number }}</RouterLink>
      <span class="block text-xs text-muted">{{ row.items_count }} item{{ row.tracking_number ? ` · resi ${row.tracking_number}` : '' }}</span>
    </template>
    <template #cell-status="{ row }"><AppBadge :tone="ORDER_STATUS[row.status]?.tone">{{ row.status_label }}</AppBadge></template>
    <template #cell-total="{ row }"><span class="font-mono">{{ rupiah(row.total) }}</span></template>
    <template #cell-created_at="{ row }"><span class="whitespace-nowrap">{{ dateTime(row.created_at) }}</span></template>
  </DataTable>
</template>
