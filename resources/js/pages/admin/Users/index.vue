<script setup>
// PL-03 — cari pengguna (tabel server-side: cari, urut, halaman).
import { Search } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { usePagedList } from '@/composables/usePagedList'
import { date, dateTime } from '@/lib/format'

const list = usePagedList(adminApi.users, { q: '' }, { sort: '-created_at' })
const { filters, search } = list
const TONE = { active: ['ok', 'aktif'], blocked: ['danger', 'diblokir'], deleted: ['muted', 'dihapus'] }
const COLUMNS = [
  { key: 'name', label: 'Nama', sortable: true },
  { key: 'email', label: 'Email', sortable: true },
  { key: 'status', label: 'Status' },
  { key: 'last_login_at', label: 'Login terakhir', sortable: true },
  { key: 'created_at', label: 'Daftar', sortable: true },
]
</script>

<template>
  <PageHeader title="Pengguna" />
  <form class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap" @submit.prevent="search">
    <AppInput v-model="filters.q" type="search" placeholder="Email, nama, atau handle" aria-label="Cari pengguna" class="col-span-2 sm:max-w-xs" />
    <AppButton type="submit" variant="secondary" class="col-span-2 sm:col-auto"><Search class="size-5" /> Cari</AppButton>
  </form>
  <DataTable
    dense :columns="COLUMNS" :rows="list.rows.value" :meta="list.meta.value" :sort="list.sort.value" :loading="list.loading.value"
    empty-text="Tidak ada pengguna yang cocok." @sort="list.toggleSort" @page="list.goto" @per-page="list.setPerPage"
  >
    <template #cell-name="{ row: u }"><RouterLink v-if="u.status !== 'deleted'" :to="`/users/${u.id}`">{{ u.name }}</RouterLink><span v-else>{{ u.name }}</span></template>
    <template #cell-status="{ row: u }"><AppBadge :tone="TONE[u.status][0]">{{ TONE[u.status][1] }}</AppBadge></template>
    <template #cell-last_login_at="{ row: u }"><span class="font-mono">{{ dateTime(u.last_login_at) }}</span></template>
    <template #cell-created_at="{ row: u }"><span class="font-mono">{{ date(u.created_at) }}</span></template>
  </DataTable>
</template>
