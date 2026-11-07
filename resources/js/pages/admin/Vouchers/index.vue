<script setup>
// Voucher checkout paket — persen/nominal, kuota, masa berlaku, paket tertentu. Terpakai dihitung saat invoice lunas
// (1× per workspace). Voucher 100% langsung mengaktifkan paket tanpa gateway.
import { computed, reactive, ref } from 'vue'
import { Pencil, Plus } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppToggle from '@/components/ui/AppToggle/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { useAction, useAsync } from '@/composables/useAsync'
import { usePagedList } from '@/composables/usePagedList'
import { date, num, rupiah } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const list = usePagedList(adminApi.vouchers, { q: '', active: '' }, { sort: '-created_at', perPage: 25 })
const COLUMNS = [
  { key: 'code', label: 'Kode', sortable: true }, { key: 'value', label: 'Potongan' }, { key: 'plans', label: 'Berlaku untuk' },
  { key: 'redeemed', label: 'Terpakai', sortable: true }, { key: 'ends_at', label: 'Berakhir', sortable: true }, { key: 'status', label: 'Status' }, { key: 'act', label: '' },
]
const { data: plans } = useAsync(() => (auth.can('plans.manage') ? adminApi.plans() : Promise.resolve({ data: [] })))
const planOptions = computed(() => (plans.value ?? []).filter((p) => p.price_monthly > 0).map((p) => ({ value: p.key, label: p.name })))
const planName = (k) => plans.value?.find((p) => p.key === k)?.name ?? k
const expired = (v) => v.ends_at && new Date(v.ends_at) < new Date()

const { busy, run } = useAction()
const editing = ref(null)
const form = reactive({})
const errors = ref({})
const toDate = (iso) => (iso ? iso.slice(0, 10) : '')
function open(v) {
  errors.value = {}
  Object.assign(form, v
    ? { ...v, plan_keys: [...v.plan_keys], starts_at: toDate(v.starts_at), ends_at: toDate(v.ends_at), max_redemptions: v.max_redemptions ?? '' }
    : { code: '', description: '', kind: 'percent', value: 10, max_redemptions: '', plan_keys: [], starts_at: '', ends_at: '', is_active: true })
  editing.value = v ?? 'new'
}
async function save() {
  errors.value = {}
  const body = {
    code: form.code, description: form.description || null, kind: form.kind, value: Number(form.value),
    max_redemptions: form.max_redemptions === '' ? null : Number(form.max_redemptions), plan_keys: form.plan_keys,
    starts_at: form.starts_at || null, ends_at: form.ends_at ? `${form.ends_at}T23:59:59+07:00` : null, is_active: form.is_active,
  }
  try {
    await run(() => (editing.value === 'new' ? adminApi.createVoucher(body) : adminApi.updateVoucher(editing.value.id, body)), 'Voucher disimpan.')
    editing.value = null
    list.reload()
  } catch (e) { errors.value = e.fields ?? {} }
}
</script>

<template>
  <PageHeader eyebrow="Bisnis" title="Voucher">
    <AppButton @click="open(null)"><Plus class="size-5" /> Voucher baru</AppButton>
  </PageHeader>
  <form class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap" @submit.prevent="list.search">
    <AppInput v-model="list.filters.q" placeholder="Cari kode / deskripsi" aria-label="Cari voucher" class="col-span-2 sm:max-w-[260px]" />
    <AppSelect v-model="list.filters.active" placeholder="Semua voucher" class="sm:!w-auto" aria-label="Filter" :options="[{ value: '1', label: 'Sedang berlaku' }]" @update:model-value="list.search" />
    <AppButton type="submit" variant="secondary" class="col-span-2 sm:col-auto">Saring</AppButton>
  </form>
  <DataTable dense :columns="COLUMNS" :rows="list.rows.value" :meta="list.meta.value" :sort="list.sort.value" :loading="list.loading.value"
    empty-text="Belum ada voucher." @sort="list.toggleSort" @page="list.goto" @per-page="list.setPerPage">
    <template #cell-code="{ row: v }"><span class="font-mono font-semibold">{{ v.code }}</span><span v-if="v.description" class="block text-xs text-muted">{{ v.description }}</span></template>
    <template #cell-value="{ row: v }"><span class="font-mono">{{ v.kind === 'percent' ? `${v.value}%` : rupiah(v.value) }}</span></template>
    <template #cell-plans="{ row: v }">{{ v.plan_keys.length ? v.plan_keys.map(planName).join(', ') : 'Semua paket' }}</template>
    <template #cell-redeemed="{ row: v }"><span class="font-mono">{{ num(v.redeemed) }}{{ v.max_redemptions ? ` / ${num(v.max_redemptions)}` : '' }}</span></template>
    <template #cell-ends_at="{ row: v }">{{ v.ends_at ? date(v.ends_at) : 'Tanpa batas' }}</template>
    <template #cell-status="{ row: v }">
      <AppBadge :tone="!v.is_active || expired(v) ? 'muted' : 'ok'">{{ !v.is_active ? 'Nonaktif' : expired(v) ? 'Berakhir' : 'Aktif' }}</AppBadge>
    </template>
    <template #cell-act="{ row: v }"><AppButton variant="ghost" :aria-label="`Ubah ${v.code}`" @click="open(v)"><Pencil class="size-4" /></AppButton></template>
  </DataTable>

  <AppModal v-if="editing" :title="editing === 'new' ? 'Voucher baru' : `Ubah ${editing.code}`" description="Voucher berlaku saat checkout paket. Satu workspace hanya bisa memakai voucher yang sama sekali." @close="editing = null">
    <form id="voucher-form" class="grid gap-4" @submit.prevent="save">
      <div class="grid gap-4 sm:grid-cols-2">
        <AppField label="Kode" hint="Huruf, angka, - dan _" :error="errors.code?.[0]"><AppInput v-model="form.code" code maxlength="32" required class="uppercase" /></AppField>
        <AppField label="Kuota pemakaian" hint="Kosong = tanpa batas" :error="errors.max_redemptions?.[0]"><AppInput v-model="form.max_redemptions" type="number" min="1" /></AppField>
      </div>
      <AppField label="Deskripsi (tampil ke pelanggan)" :error="errors.description?.[0]"><AppInput v-model="form.description" maxlength="160" /></AppField>
      <div class="grid gap-4 sm:grid-cols-[auto_1fr] sm:items-end">
        <AppSegmented v-model="form.kind" label="Jenis potongan" :options="[{ value: 'percent', label: 'Persen' }, { value: 'amount', label: 'Nominal (Rp)' }]" />
        <AppField :label="form.kind === 'percent' ? 'Potongan (%)' : 'Potongan (Rp)'" :error="errors.value?.[0]">
          <AppInput v-model="form.value" type="number" min="1" :max="form.kind === 'percent' ? 100 : undefined" required />
        </AppField>
      </div>
      <AppField label="Berlaku untuk paket" hint="Kosong = semua paket berbayar">
        <AppSelect v-model="form.plan_keys" multiple :options="planOptions" placeholder="Semua paket" />
      </AppField>
      <div class="grid gap-4 sm:grid-cols-2">
        <AppField label="Mulai" :error="errors.starts_at?.[0]"><AppInput v-model="form.starts_at" type="date" /></AppField>
        <AppField label="Berakhir" :error="errors.ends_at?.[0]"><AppInput v-model="form.ends_at" type="date" /></AppField>
      </div>
      <AppToggle v-model="form.is_active" label="Aktif" />
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="editing = null">Batal</AppButton>
      <AppButton type="submit" form="voucher-form" :loading="busy">Simpan voucher</AppButton>
    </template>
  </AppModal>
</template>
