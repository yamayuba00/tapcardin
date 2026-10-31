<script setup>
// PL-02 — daftar & cari workspace (nama, subdomain, email owner).
import { Search } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { usePagedList } from '@/composables/usePagedList'
import { date } from '@/lib/format'

const list = usePagedList(adminApi.tenants, { q: '', type: '', status: '' }, { sort: '-created_at' })
const { filters, search } = list
const COLUMNS = [
  { key: 'name', label: 'Nama', sortable: true }, { key: 'type', label: 'Tipe', sortable: true }, { key: 'owner', label: 'Owner' },
  { key: 'plan', label: 'Paket' }, { key: 'status', label: 'Status', sortable: true }, { key: 'created_at', label: 'Dibuat', sortable: true },
]
const STATUSES = ['active', 'trialing', 'past_due', 'suspended', 'pending_deletion'].map((s) => ({ value: s, label: s }))
</script>

<template>
  <PageHeader title="Workspace" />
  <form class="flex flex-wrap gap-2" @submit.prevent="search">
    <AppInput v-model="filters.q" type="search" placeholder="Nama, subdomain, atau email owner" aria-label="Cari workspace" class="max-w-xs" />
    <AppSelect v-model="filters.type" placeholder="Semua tipe" :options="[{ value: 'personal', label: 'Personal' }, { value: 'company', label: 'Perusahaan' }]" class="!w-auto" aria-label="Tipe" />
    <AppSelect v-model="filters.status" placeholder="Semua status" :options="STATUSES" class="!w-auto" aria-label="Status" />
    <AppButton type="submit" variant="secondary"><Search class="size-5" /> Cari</AppButton>
  </form>

  <DataTable
    dense :columns="COLUMNS" :rows="list.rows.value" :meta="list.meta.value" :sort="list.sort.value" :loading="list.loading.value"
    empty-text="Tidak ada workspace yang cocok." @sort="list.toggleSort" @page="list.goto" @per-page="list.setPerPage"
  >
    <template #cell-name="{ row: t }"><RouterLink :to="`/tenants/${t.id}`">{{ t.name }}</RouterLink><span v-if="t.slug" class="block font-mono text-xs text-muted">{{ t.slug }}</span></template>
    <template #cell-type="{ row: t }">{{ t.type === 'personal' ? 'Personal' : 'Perusahaan' }}</template>
    <template #cell-owner="{ row: t }">{{ t.owner?.email }}</template>
    <template #cell-status="{ row: t }"><AppBadge :tone="t.status === 'suspended' ? 'danger' : 'ok'">{{ t.status }}</AppBadge></template>
    <template #cell-created_at="{ row: t }"><span class="font-mono">{{ date(t.created_at) }}</span></template>
  </DataTable>
</template>
