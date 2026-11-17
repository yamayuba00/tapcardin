<script setup>
// Rekap lead per karyawan (kartu aktif, tap & lead 30 hari, total lead) — urutan di klien.
import { computed, ref } from 'vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import { companyApi } from '@/api/workspace'
import { useAsync } from '@/composables/useAsync'
import { num } from '@/lib/format'

const { data } = useAsync(() => companyApi.leads())
const sort = ref('-leads_30')
const COLUMNS = [
  { key: 'name', label: 'Karyawan', sortable: true }, { key: 'cards', label: 'Kartu aktif', sortable: true, align: 'right' },
  { key: 'taps_30', label: 'Tap 30 hari', sortable: true, align: 'right' }, { key: 'leads_30', label: 'Lead 30 hari', sortable: true, align: 'right' },
  { key: 'leads_total', label: 'Total lead', sortable: true, align: 'right' },
]
const rows = computed(() => {
  if (!data.value) return null
  const key = sort.value.replace('-', '')
  const dir = sort.value.startsWith('-') ? -1 : 1
  return [...data.value].sort((a, b) => (key === 'name' ? a.name.localeCompare(b.name) : a[key] - b[key]) * dir)
})
const toggle = (key) => { sort.value = sort.value === `-${key}` ? key : `-${key}` }
</script>

<template>
  <DataTable dense row-key="user_id" :columns="COLUMNS" :rows="rows" :sort="sort" :loading="!data" empty-text="Belum ada karyawan aktif." @sort="toggle">
    <template #cell-name="{ row: m }">{{ m.name }}<span class="block text-sm text-muted">{{ m.email }}</span></template>
    <template v-for="k in ['cards', 'taps_30', 'leads_30', 'leads_total']" :key="k" #[`cell-${k}`]="{ row: m }"><span class="font-mono">{{ num(m[k]) }}</span></template>
  </DataTable>
</template>
