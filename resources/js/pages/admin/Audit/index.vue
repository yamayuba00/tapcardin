<script setup>
// PL-17 — semua aksi staf & perubahan sensitif, filter per cakupan/aksi/tanggal.
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { usePagedList } from '@/composables/usePagedList'
import { dateTime } from '@/lib/format'

const list = usePagedList(adminApi.audit, { scope: '', action: '', from: '', to: '' }, { sort: '-at', perPage: 50 })
const { filters, search } = list
const COLUMNS = [
  { key: 'at', label: 'Waktu', sortable: true }, { key: 'actor', label: 'Pelaku' }, { key: 'action', label: 'Aksi', sortable: true },
  { key: 'subject', label: 'Objek' }, { key: 'diff', label: 'Perubahan' },
]
const json = (v) => JSON.stringify(v)
</script>

<template>
  <PageHeader title="Audit log" />
  <form class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap" @submit.prevent="search">
    <AppSelect v-model="filters.scope" placeholder="Semua cakupan" :options="[{ value: 'platform', label: 'Platform' }, { value: 'tenant', label: 'Workspace' }]" class="col-span-2 sm:!w-auto" aria-label="Cakupan" />
    <AppInput v-model="filters.action" placeholder="Aksi, mis. card." aria-label="Aksi" class="sm:max-w-[200px]" />
    <AppInput v-model="filters.from" type="date" aria-label="Dari tanggal" class="sm:!w-auto" />
    <AppInput v-model="filters.to" type="date" aria-label="Sampai tanggal" class="sm:!w-auto" />
    <AppButton type="submit" variant="secondary" class="col-span-2 sm:col-auto">Saring</AppButton>
  </form>
  <DataTable
    dense :columns="COLUMNS" :rows="list.rows.value" :meta="list.meta.value" :sort="list.sort.value" :loading="list.loading.value"
    empty-text="Belum ada catatan." @sort="list.toggleSort" @page="list.goto" @per-page="list.setPerPage"
  >
    <template #cell-at="{ row: l }"><span class="font-mono whitespace-nowrap">{{ dateTime(l.at) }}</span></template>
    <template #cell-actor="{ row: l }">{{ l.actor ?? 'sistem' }}<AppBadge v-if="l.impersonated" tone="danger" class="ml-2">impersonation</AppBadge></template>
    <template #cell-action="{ row: l }"><span class="font-mono">{{ l.action }}</span><span class="block text-xs text-muted">{{ l.scope }}{{ l.tenant_id ? ' · t' + l.tenant_id : '' }}</span></template>
    <template #cell-subject="{ row: l }"><span class="font-mono">{{ l.subject }}</span></template>
    <template #cell-diff="{ row: l }"><span class="block max-w-md font-mono text-xs break-all"><template v-if="l.before">{{ json(l.before) }} → </template>{{ l.after ? json(l.after) : '' }}</span></template>
  </DataTable>
</template>
