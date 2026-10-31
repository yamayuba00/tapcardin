<script setup>
// PL-06 — daftar batch & buat batch baru (NTAG213/215/216; 424 DNA menyusul v1.5).
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Plus } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { useAction } from '@/composables/useAsync'
import { usePagedList } from '@/composables/usePagedList'
import { date } from '@/lib/format'

const router = useRouter()
const list = usePagedList(adminApi.batches, { q: '', status: '' }, { sort: '-created_at', perPage: 25 })
const COLUMNS = [
  { key: 'code', label: 'Kode', sortable: true }, { key: 'chip_type', label: 'Chip' },
  { key: 'quantity', label: 'Jumlah', sortable: true, align: 'right' }, { key: 'encoded_count', label: 'Encode', align: 'right' },
  { key: 'qc_count', label: 'QC', align: 'right' }, { key: 'holder', label: 'Pembeli' },
  { key: 'status', label: 'Status', sortable: true }, { key: 'created_at', label: 'Dibuat', sortable: true },
]
const STATUSES = [['draft', 'Draf'], ['tokens_generated', 'Token dibuat'], ['sent_to_print', 'Di percetakan'], ['printed', 'Tercetak'], ['encoding', 'Encode'], ['qc', 'QC'], ['completed', 'Selesai'], ['cancelled', 'Batal']].map(([value, label]) => ({ value, label }))
const { busy, run } = useAction()
const form = reactive({ chip_type: 'ntag213', quantity: 10, holder_email: '', vendor: '' })
const errors = ref({})
const CHIPS = ['ntag213', 'ntag215', 'ntag216'].map((c) => ({ value: c, label: c.toUpperCase() }))
const tone = (s) => (s === 'completed' ? 'ok' : s === 'cancelled' ? 'muted' : 'info')

async function create() {
  errors.value = {}
  try {
    const res = await run(() => adminApi.createBatch({ ...form, holder_email: form.holder_email || null }), 'Batch dibuat. Lanjutkan dengan membuat token.')
    router.push(`/batches/${res.data.id}`)
  } catch (e) { errors.value = e.fields }
}
</script>

<template>
  <PageHeader title="Batch produksi" />
  <AppPanel title="Batch baru">
    <form class="grid items-start gap-4 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="create">
      <AppField label="Chip"><AppSelect v-model="form.chip_type" :options="CHIPS" /></AppField>
      <AppField label="Jumlah kartu" :error="errors.quantity?.[0]"><AppInput v-model.number="form.quantity" type="number" min="1" max="1000" /></AppField>
      <AppField label="Email pembeli (opsional)" :error="errors.holder_email?.[0]"><AppInput v-model="form.holder_email" type="email" placeholder="aktivasi tanpa kode" /></AppField>
      <AppField label="Vendor cetak"><AppInput v-model="form.vendor" /></AppField>
      <AppButton type="submit" :loading="busy" class="lg:mt-7"><Plus class="size-5" /> Buat batch</AppButton>
    </form>
  </AppPanel>

  <form class="flex flex-wrap gap-2" @submit.prevent="list.search">
    <AppInput v-model="list.filters.q" type="search" placeholder="Kode batch atau vendor" aria-label="Cari batch" class="max-w-xs" />
    <AppSelect v-model="list.filters.status" placeholder="Semua status" :options="STATUSES" class="!w-auto" aria-label="Status" />
    <AppButton type="submit" variant="secondary">Cari</AppButton>
  </form>
  <DataTable
    dense :columns="COLUMNS" :rows="list.rows.value" :meta="list.meta.value" :sort="list.sort.value" :loading="list.loading.value"
    empty-text="Belum ada batch." @sort="list.toggleSort" @page="list.goto" @per-page="list.setPerPage"
  >
    <template #cell-code="{ row: b }"><RouterLink :to="`/batches/${b.id}`" class="font-mono">{{ b.code }}</RouterLink></template>
    <template #cell-chip_type="{ row: b }">{{ b.chip_type.toUpperCase() }}</template>
    <template #cell-quantity="{ row: b }"><span class="font-mono">{{ b.quantity }}</span></template>
    <template #cell-encoded_count="{ row: b }"><span class="font-mono">{{ b.encoded_count }}</span></template>
    <template #cell-qc_count="{ row: b }"><span class="font-mono">{{ b.qc_count }}</span></template>
    <template #cell-holder="{ row: b }">{{ b.holder?.email ?? 'Stok' }}</template>
    <template #cell-status="{ row: b }"><AppBadge :tone="tone(b.status)">{{ b.status_label }}</AppBadge></template>
    <template #cell-created_at="{ row: b }"><span class="font-mono">{{ date(b.created_at) }}</span></template>
  </DataTable>
</template>
