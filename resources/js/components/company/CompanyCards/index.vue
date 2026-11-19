<script setup>
// Kartu karyawan: stok perusahaan ditugaskan ke karyawan (karyawan mengaktifkan sendiri), atau ditarik kembali ke stok.
import { computed, ref } from 'vue'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import { companyApi } from '@/api/workspace'
import { useAction } from '@/composables/useAsync'
import { usePagedList } from '@/composables/usePagedList'

const list = usePagedList(companyApi.cards, { filter: '' }, { url: false, sort: '-updated_at' })
const { busy, run } = useAction()
const COLUMNS = [{ key: 'card', label: 'Kartu' }, { key: 'status', label: 'Status', sortable: true }, { key: 'person', label: 'Karyawan' }, { key: 'actions' }]
const setFilter = (v) => { list.filters.filter = v; list.search() }
const replace = (c) => { list.rows.value = list.rows.value.map((x) => (x.token === c.token ? c : x)) }

const members = ref([])
const options = computed(() => members.value.map((m) => ({ value: m.user_id, label: m.name, description: m.email })))
const assigning = ref(null)
const target = ref('')
async function openAssign(card) {
  assigning.value = card
  target.value = ''
  if (!members.value.length) members.value = (await companyApi.assignees()).data ?? [] // Manager: hanya anggota timnya
}
async function assign() {
  const res = await run(() => companyApi.assignCard(assigning.value.token, target.value), 'Kartu ditugaskan. Karyawan bisa mengaktifkannya sekarang.').catch(() => null)
  if (res) replace(res.data)
  assigning.value = null
}
const releasing = ref(null)
async function release() {
  const res = await run(() => companyApi.unassignCard(releasing.value.token), 'Kartu kembali ke stok perusahaan.').catch(() => null)
  if (res) replace(res.data)
  releasing.value = null
}
</script>

<template>
  <AppSegmented :model-value="list.filters.filter" label="Tampilkan" :options="[{ value: '', label: 'Semua kartu' }, { value: 'pool', label: 'Stok belum ditugaskan' }]" @update:model-value="setFilter" />

  <DataTable
    dense row-key="token" :columns="COLUMNS" :rows="list.rows.value" :meta="list.meta.value" :sort="list.sort.value" :loading="list.loading.value"
    empty-text="Belum ada kartu fisik perusahaan. Pesan kartu di menu Pesanan." @sort="list.toggleSort" @page="list.goto" @per-page="list.setPerPage"
  >
    <template #cell-card="{ row: c }"><span class="font-mono">{{ c.short_code }}</span><span v-if="c.label" class="block text-sm text-muted">{{ c.label }}</span></template>
    <template #cell-status="{ row: c }"><AppBadge :tone="c.tone">{{ c.status_label }}</AppBadge></template>
    <template #cell-person="{ row: c }">
      <template v-if="c.holder">{{ c.holder.name }}<span class="block text-sm text-muted">{{ c.holder.email }}</span></template>
      <template v-else-if="c.assigned">{{ c.assigned.name }}<span class="block text-sm text-muted">Ditugaskan, belum diaktifkan</span></template>
      <span v-else class="text-muted">Stok perusahaan</span>
    </template>
    <template #cell-actions="{ row: c }">
      <AppButton v-if="c.holder || c.assigned" variant="ghost" :disabled="busy" @click="releasing = c">Tarik ke stok</AppButton>
      <AppButton v-else variant="ghost" :disabled="busy" @click="openAssign(c)">Tugaskan</AppButton>
    </template>
  </DataTable>

  <AppModal v-if="assigning" size="sm" title="Tugaskan kartu" :description="`Kartu ${assigning.short_code} hanya bisa diaktifkan oleh karyawan yang dipilih.`" @close="assigning = null">
    <form id="card-assign" class="grid gap-4" @submit.prevent="assign">
      <AppField label="Karyawan"><AppSelect v-model="target" :options="options" searchable placeholder="Pilih karyawan" /></AppField>
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="assigning = null">Batal</AppButton>
      <AppButton type="submit" form="card-assign" :loading="busy" :disabled="!target">Tugaskan</AppButton>
    </template>
  </AppModal>

  <AppModal v-if="releasing" size="sm" title="Tarik kartu ke stok?" description="Kartu dilepas dari karyawan. Kartu yang sudah aktif kembali ke status belum diaktifkan, tanpa profil dan kunci login, lalu bisa ditugaskan ke karyawan lain." @close="releasing = null">
    <template #footer>
      <AppButton variant="secondary" @click="releasing = null">Batal</AppButton>
      <AppButton variant="danger" :loading="busy" @click="release">Tarik ke stok</AppButton>
    </template>
  </AppModal>
</template>
