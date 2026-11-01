<script setup>
// Panel → Pesanan: antrean pesanan kartu & lanyard pelanggan. Yang "Dibayar" menunggu produksi dimulai.
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { usePagedList } from '@/composables/usePagedList'
import { dateTime, rupiah } from '@/lib/format'
import { ORDER_STATUS } from '@/lib/orders'

const list = usePagedList(adminApi.orders, { status: 'paid', q: '' }, { sort: '-created_at', perPage: 25 })
const COLUMNS = [
  { key: 'number', label: 'Pesanan', sortable: true }, { key: 'tenant', label: 'Pelanggan' }, { key: 'status', label: 'Status' },
  { key: 'total', label: 'Total', sortable: true, align: 'right' }, { key: 'created_at', label: 'Dibuat', sortable: true },
]
const TABS = [
  { value: 'paid', label: 'Perlu diproduksi' }, { value: 'in_production', label: 'Produksi' }, { value: 'shipped', label: 'Dikirim' },
  { value: 'pending_payment', label: 'Belum bayar' }, { value: '', label: 'Semua' },
]
const setStatus = (v) => { list.filters.status = v; list.search() }
</script>

<template>
  <PageHeader eyebrow="Produksi" title="Pesanan" />
  <div class="flex flex-wrap items-center gap-3">
    <AppSegmented :model-value="list.filters.status" label="Status pesanan" :options="TABS" @update:model-value="setStatus" />
    <form class="flex w-full gap-2 sm:ml-auto sm:w-auto" @submit.prevent="list.search">
      <AppInput v-model="list.filters.q" placeholder="No. pesanan, penerima, workspace" aria-label="Cari pesanan" class="min-w-0 flex-1 sm:max-w-[260px]" />
      <AppButton type="submit" variant="secondary">Cari</AppButton>
    </form>
  </div>
  <DataTable
    dense :columns="COLUMNS" :rows="list.rows.value" :meta="list.meta.value" :sort="list.sort.value" :loading="list.loading.value"
    empty-text="Tidak ada pesanan di status ini." @sort="list.toggleSort" @page="list.goto" @per-page="list.setPerPage"
  >
    <template #cell-number="{ row }">
      <RouterLink :to="`/orders/${row.id}`" class="font-mono">{{ row.number }}</RouterLink>
      <span class="block text-xs text-muted">{{ row.items_count }} item</span>
    </template>
    <template #cell-tenant="{ row }">{{ row.tenant?.name }}<span class="block text-xs text-muted">{{ row.customer }}</span></template>
    <template #cell-status="{ row }"><AppBadge :tone="ORDER_STATUS[row.status]?.tone">{{ row.status_label }}</AppBadge></template>
    <template #cell-total="{ row }"><span class="font-mono">{{ rupiah(row.total) }}</span></template>
    <template #cell-created_at="{ row }"><span class="whitespace-nowrap">{{ dateTime(row.created_at) }}</span></template>
  </DataTable>
</template>
