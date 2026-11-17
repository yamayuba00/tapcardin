<script setup>
// PL-02 — workspace dipisah per tab: Perusahaan (subdomain, kartu aktif/maks, masa aktif 6 bulan) & Perorangan.
import { computed, ref } from 'vue'
import { Plus, Search } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import TenantCreate from '@/components/admin/TenantCreate/index.vue'
import { adminApi } from '@/api/admin'
import { usePagedList } from '@/composables/usePagedList'
import { useAuthStore } from '@/stores/auth'
import { date, num } from '@/lib/format'

const auth = useAuthStore()
const creating = ref(false)

const list = usePagedList(({ tab, ...p }) => adminApi.tenants({ ...p, type: tab }), { tab: 'company', q: '', status: '' }, { sort: '-created_at' })
const { filters, search } = list
const company = computed(() => filters.tab === 'company')
const COLUMNS = computed(() => (company.value
  ? [
      { key: 'name', label: 'Perusahaan', sortable: true }, { key: 'owner', label: 'Owner' }, { key: 'plan', label: 'Paket' },
      { key: 'cards', label: 'Kartu aktif', align: 'right' }, { key: 'active_until', label: 'Aktif s/d' }, { key: 'status', label: 'Status' },
    ]
  : [
      { key: 'name', label: 'Nama', sortable: true }, { key: 'owner', label: 'Email' }, { key: 'plan', label: 'Paket' },
      { key: 'cards', label: 'Kartu aktif', align: 'right' }, { key: 'status', label: 'Status', sortable: true }, { key: 'created_at', label: 'Dibuat', sortable: true },
    ]))
const STATUSES = [['active', 'Aktif'], ['past_due', 'Menunggak'], ['suspended', 'Disuspend'], ['pending_deletion', 'Akan dihapus']].map(([value, label]) => ({ value, label }))

// status perusahaan mengikuti langganan (uji coba → aktif → tenggang → habis); suspend selalu menang
const SUB = { trialing: ['info', 'Uji coba'], active: ['ok', 'Aktif'], past_due: ['warn', 'Masa tenggang'], expired: ['danger', 'Masa aktif habis'], cancelled: ['muted', 'Berhenti'] }
const TENANT = { active: ['ok', 'Aktif'], past_due: ['warn', 'Menunggak'], suspended: ['danger', 'Disuspend'], pending_deletion: ['muted', 'Akan dihapus'] }
function badge(t) {
  if (t.status === 'suspended') return TENANT.suspended
  if (t.type !== 'personal') return SUB[t.subscription_status] ?? ['muted', 'Belum berlangganan']
  return TENANT[t.status] ?? ['muted', t.status]
}
</script>

<template>
  <PageHeader title="Workspace">
    <AppButton v-if="company && auth.can('tenants.manage')" @click="creating = true"><Plus class="size-5" /> Buat workspace</AppButton>
  </PageHeader>
  <TenantCreate v-if="creating" @created="list.reload()" @close="creating = false" />
  <AppSegmented v-model="filters.tab" label="Jenis workspace" :options="[{ value: 'company', label: 'Perusahaan' }, { value: 'personal', label: 'Perorangan' }]" class="justify-self-start" />
  <p class="text-sm text-muted">
    <template v-if="company">Langganan workspace & subdomain per 6 bulan, dibedakan batas kartu aktif. Kartu fisik dipesan terpisah.</template>
    <template v-else>Workspace pribadi tiap pengguna di app. Paket bulanan / tahunan.</template>
  </p>
  <form class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap" @submit.prevent="search">
    <AppInput v-model="filters.q" type="search" :placeholder="company ? 'Nama, subdomain, atau email owner' : 'Nama atau email'" aria-label="Cari workspace" class="col-span-2 sm:max-w-xs" />
    <AppSelect v-model="filters.status" placeholder="Semua status" :options="STATUSES" class="sm:!w-auto" aria-label="Status" />
    <AppButton type="submit" variant="secondary"><Search class="size-5" /> Cari</AppButton>
  </form>

  <DataTable
    dense :columns="COLUMNS" :rows="list.rows.value" :meta="list.meta.value" :sort="list.sort.value" :loading="list.loading.value"
    :empty-text="company ? 'Belum ada workspace perusahaan.' : 'Tidak ada workspace perorangan yang cocok.'" @sort="list.toggleSort" @page="list.goto" @per-page="list.setPerPage"
  >
    <template #cell-name="{ row: t }"><RouterLink :to="`/tenants/${t.id}`">{{ t.name }}</RouterLink><span v-if="t.slug" class="block font-mono text-xs text-muted">{{ t.slug }}</span></template>
    <template #cell-owner="{ row: t }">{{ t.owner?.email }}</template>
    <template #cell-cards="{ row: t }"><span class="font-mono">{{ num(t.cards_active) }}<template v-if="t.cards_max != null"> / {{ num(t.cards_max) }}</template></span></template>
    <template #cell-active_until="{ row: t }"><span class="font-mono">{{ t.active_until ? date(t.active_until) : '—' }}</span></template>
    <template #cell-status="{ row: t }"><AppBadge :tone="badge(t)[0]">{{ badge(t)[1] }}</AppBadge></template>
    <template #cell-created_at="{ row: t }"><span class="font-mono">{{ date(t.created_at) }}</span></template>
  </DataTable>
</template>
