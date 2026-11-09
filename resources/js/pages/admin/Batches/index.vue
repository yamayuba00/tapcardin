<script setup>
// PL-06 — batch produksi, dipisah per jalur kartu (ditentukan otomatis dari asal batch):
//   Jual langsung — stok desain seragam untuk dijual petugas (Jual kartu); cukup isi jumlah.
//   Custom        — kartu perorangan dengan desain pemesan (biasanya dari Pesanan; manual: jumlah + email pembeli).
//   Perusahaan    — kartu workspace perusahaan, dibuat dari Pesanan perusahaan.
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ExternalLink, Plus } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import FlipCard from '@/components/studio/FlipCard/index.vue'
import { adminApi } from '@/api/admin'
import { useAction, useAsync } from '@/composables/useAsync'
import { usePagedList } from '@/composables/usePagedList'
import { date } from '@/lib/format'

const router = useRouter()
const TABS = [{ value: 'direct', label: 'Jual langsung' }, { value: 'custom', label: 'Custom' }, { value: 'company', label: 'Perusahaan' }]
const list = usePagedList(adminApi.batches, { q: '', status: '', channel: 'direct' }, { sort: '-created_at', perPage: 25 })
const channel = computed({
  get: () => list.filters.channel || 'direct',
  set: (v) => {
    list.filters.channel = v
    form.quantity = v === 'direct' ? 100 : 1 // stok jualan banyak, pesanan custom biasanya beberapa kartu
    list.search()
  },
})

const COLUMNS = computed(() => [
  { key: 'code', label: 'Kode', sortable: true },
  { key: 'quantity', label: 'Jumlah', sortable: true, align: 'right' },
  { key: 'encoded_count', label: channel.value === 'direct' ? 'Siap jual' : 'Encode', align: 'right' },
  channel.value !== 'direct' && { key: 'qc_count', label: 'QC', align: 'right' },
  channel.value === 'custom' && { key: 'holder', label: 'Pembeli' },
  channel.value === 'company' && { key: 'tenant', label: 'Perusahaan' },
  { key: 'status', label: 'Status', sortable: true }, { key: 'created_at', label: 'Dibuat', sortable: true },
].filter(Boolean))
const STATUSES = [['draft', 'Draf'], ['tokens_generated', 'Token dibuat'], ['sent_to_print', 'Di percetakan'], ['printed', 'Tercetak'], ['encoding', 'Encode'], ['qc', 'QC'], ['completed', 'Selesai'], ['cancelled', 'Batal']].map(([value, label]) => ({ value, label }))
const tone = (s) => (s === 'completed' ? 'ok' : s === 'cancelled' ? 'muted' : 'info')

const { busy, run } = useAction()
const form = reactive({ quantity: (list.filters.channel || 'direct') === 'direct' ? 100 : 1, holder_email: '', chip_type: 'ntag215', design: '' })
// desain depan & belakang stok jual langsung — dibuat/diubah di Design Studio, dipilih di sini
const { data: studio } = useAsync(async () => {
  const res = await adminApi.batchDesigns()
  if (!form.design && res.data.designs[0]) form.design = res.data.designs[0].id
  return res
})
const designOptions = computed(() => (studio.value?.designs ?? []).map((d) => ({ value: d.id, label: d.name })))
const picked = computed(() => studio.value?.designs.find((d) => d.id === form.design) ?? null)
const errors = ref({})
const CHIPS = ['ntag213', 'ntag215', 'ntag216'].map((c) => ({ value: c, label: c.toUpperCase() }))

async function create() {
  errors.value = {}
  const direct = channel.value === 'direct'
  try {
    const res = await run(() => adminApi.createBatch({
      chip_type: form.chip_type, quantity: form.quantity, uniform: direct, holder_email: direct ? null : form.holder_email || null,
      design: direct ? form.design || null : null,
    }), direct ? 'Stok dibuat — token sedang disiapkan.' : 'Batch dibuat — token sedang disiapkan.')
    // langsung buat token (di latar), tidak perlu langkah terpisah
    await adminApi.generateBatch(res.data.id).catch(() => null)
    router.push(`/batches/${res.data.id}`)
  } catch (e) { errors.value = e.fields ?? {} }
}
</script>

<template>
  <PageHeader title="Batch kartu" />
  <AppSegmented v-model="channel" :options="TABS" label="Jalur kartu" class="justify-self-start" />

  <!-- Jual langsung: tambah stok cukup dengan jumlah -->
  <AppPanel v-if="channel === 'direct'" title="Tambah stok jual langsung">
    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)] lg:items-start">
      <form class="grid gap-4" @submit.prevent="create">
        <AppField label="Desain kartu (depan & belakang)" :error="errors.design?.[0]">
          <AppSelect v-model="form.design" :options="designOptions" placeholder="Pilih desain" />
        </AppField>
<p v-if="picked?.per_card?.length" class="rounded-md border border-warn p-3 text-sm text-warn" role="alert">
          Desain ini memuat {{ picked.per_card.join(', ') }} yang berbeda di tiap kartu. Stok jual langsung dicetak sama semua — hapus elemen itu di Design Studio (cukup logo, tagline, & ikon NFC).
        </p>
        <p v-if="studio && !studio.designs.length" class="text-sm text-muted">Belum ada desain di akun Anda. Buat dulu di Design Studio, lalu tarik ke bawah / muat ulang halaman ini.</p>
        <a v-if="studio" :href="picked ? picked.studio_url : studio.studio_url" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center gap-1.5 justify-self-start text-sm font-semibold underline">
          <ExternalLink class="size-4" /> {{ picked ? 'Ubah desain ini di Design Studio' : 'Buat desain di Design Studio' }}
        </a>
        <div class="grid items-end gap-4 sm:grid-cols-[minmax(0,10rem)_minmax(0,10rem)_auto]">
          <AppField label="Jumlah kartu" :error="errors.quantity?.[0]"><AppInput v-model.number="form.quantity" type="number" min="1" max="1000" inputmode="numeric" /></AppField>
          <AppField label="Chip"><AppSelect v-model="form.chip_type" :options="CHIPS" /></AppField>
          <AppButton type="submit" :loading="busy"><Plus class="size-5" /> Tambah stok</AppButton>
        </div>
        <p class="text-sm text-muted">Tempelkan kartu polos di menu <b class="text-fg">Jual</b> — chip ditulis & kartu siap dijual saat itu juga.</p>
      </form>
      <div v-if="picked" class="grid grid-cols-2 gap-3 lg:grid-cols-1">
        <figure v-for="side in ['front', 'back']" :key="side" class="grid gap-1">
          <FlipCard :front="picked.front" :back="picked.back" :flipped="side === 'back'" :vars="studio.vars" :kit="picked.kit" :qr="studio.qr" />
          <figcaption class="text-center text-xs text-muted">{{ side === 'front' ? 'Depan' : 'Belakang' }}</figcaption>
        </figure>
      </div>
    </div>
  </AppPanel>

  <!-- Custom: batch manual untuk satu pembeli (pesanan dari aplikasi membuat batch-nya sendiri) -->
  <AppPanel v-else-if="channel === 'custom'" title="Batch custom manual">
    <form class="grid items-end gap-4 sm:grid-cols-[minmax(0,8rem)_minmax(0,1fr)_minmax(0,10rem)_auto]" @submit.prevent="create">
      <AppField label="Jumlah" :error="errors.quantity?.[0]"><AppInput v-model.number="form.quantity" type="number" min="1" max="1000" inputmode="numeric" /></AppField>
      <AppField label="Email pembeli" :error="errors.holder_email?.[0]"><AppInput v-model="form.holder_email" type="email" required /></AppField>
      <AppField label="Chip"><AppSelect v-model="form.chip_type" :options="CHIPS" /></AppField>
      <AppButton type="submit" :loading="busy"><Plus class="size-5" /> Buat batch</AppButton>
    </form>
    <p class="mt-3 text-sm text-muted">Pesanan custom dari aplikasi membuat batch-nya sendiri (panel → Pesanan). Pembeli mengaktifkan kartunya sendiri tanpa kode.</p>
  </AppPanel>

  <p v-else class="text-sm text-muted">Batch perusahaan dibuat dari pesanan workspace perusahaan (panel → Pesanan). Kartunya dibagikan admin perusahaan ke anggotanya.</p>

  <form class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap" @submit.prevent="list.search">
    <AppInput v-model="list.filters.q" type="search" placeholder="Kode batch" aria-label="Cari batch" class="col-span-2 sm:max-w-xs" />
    <AppSelect v-model="list.filters.status" placeholder="Semua status" :options="STATUSES" class="sm:!w-auto" aria-label="Status" />
    <AppButton type="submit" variant="secondary" class="col-span-2 sm:col-auto">Cari</AppButton>
  </form>
  <DataTable
    dense :columns="COLUMNS" :rows="list.rows.value" :meta="list.meta.value" :sort="list.sort.value" :loading="list.loading.value"
    empty-text="Belum ada batch di jalur ini." @sort="list.toggleSort" @page="list.goto" @per-page="list.setPerPage"
  >
    <template #cell-code="{ row: b }"><RouterLink :to="`/batches/${b.id}`" class="font-mono">{{ b.code }}</RouterLink></template>
    <template #cell-quantity="{ row: b }"><span class="font-mono">{{ b.quantity }}</span></template>
    <template #cell-encoded_count="{ row: b }"><span class="font-mono">{{ channel === 'direct' ? b.qc_count : b.encoded_count }}</span></template>
    <template #cell-qc_count="{ row: b }"><span class="font-mono">{{ b.qc_count }}</span></template>
    <template #cell-holder="{ row: b }">{{ b.holder?.email ?? '—' }}</template>
    <template #cell-tenant="{ row: b }">{{ b.tenant ?? '—' }}</template>
    <template #cell-status="{ row: b }"><AppBadge :tone="tone(b.status)">{{ b.status_label }}</AppBadge></template>
    <template #cell-created_at="{ row: b }"><span class="font-mono">{{ date(b.created_at) }}</span></template>
  </DataTable>
</template>
