<script setup>
// PL-15 — undang staf, tetapkan peran platform, cabut akses. Pemilik Platform minimal 1. Tabel staf server-side.
import { computed, reactive, ref } from 'vue'
import { KeyRound, Plus, Search } from 'lucide-vue-next'
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
import { dateTime } from '@/lib/format'

const list = usePagedList(adminApi.staff, { q: '', status: '' }, { sort: 'name' })
const { busy, run } = useAction()
const invite = reactive({ email: '', role_id: null })
const issued = ref(null)
const roles = computed(() => (list.meta.value?.roles ?? []).map((r) => ({ value: r.id, label: r.name })))
const COLUMNS = [
  { key: 'name', label: 'Staf', sortable: true }, { key: 'role', label: 'Peran' }, { key: 'status', label: 'Status', sortable: true },
  { key: 'last_reverified_at', label: 'Verifikasi terakhir', sortable: true }, { key: 'actions', label: '' },
]

async function act(fn, message) {
  const res = await run(fn, message).catch(() => null)
  if (res) {
    issued.value = res.data.issued
    list.reload()
  }
  return res
}
async function submit() {
  if (await act(() => adminApi.inviteStaff(invite.email, invite.role_id ?? roles.value[0]?.value), `${invite.email} sekarang staf.`)) invite.email = ''
}
</script>

<template>
  <PageHeader title="Staf & peran platform" />
  <AppPanel v-if="issued?.temporary_password" title="Password sementara">
    <p class="text-sm">Berikan ke <strong>{{ issued.email }}</strong> lewat kanal aman. Password ini hanya ditampilkan sekali; minta staf menggantinya di menu Akun.</p>
    <p class="mt-3 rounded-md bg-surface-2 p-4 text-center font-mono text-2xl tracking-[.15em] select-all">{{ issued.temporary_password }}</p>
  </AppPanel>

  <AppPanel title="Undang staf">
    <form class="grid items-end gap-4 sm:grid-cols-[1fr_1fr_auto]" @submit.prevent="submit">
      <AppField label="Email"><AppInput v-model="invite.email" type="email" required /></AppField>
      <AppField label="Peran"><AppSelect :model-value="invite.role_id ?? roles[0]?.value" :options="roles" @update:model-value="(v) => (invite.role_id = Number(v))" /></AppField>
      <AppButton type="submit" :loading="busy"><Plus class="size-5" /> Undang</AppButton>
    </form>
    <p class="mt-3 text-sm text-muted">Staf baru mendapat password sementara untuk masuk ke admin dengan email + password. Staf tidak otomatis melihat isi lead pelanggan (PRD §5.1).</p>
  </AppPanel>

  <form class="flex flex-wrap gap-2" @submit.prevent="list.search">
    <AppInput v-model="list.filters.q" type="search" placeholder="Nama atau email staf" aria-label="Cari staf" class="max-w-xs" />
    <AppSelect v-model="list.filters.status" placeholder="Semua status" :options="[{ value: 'active', label: 'Aktif' }, { value: 'revoked', label: 'Dicabut' }]" class="!w-auto" aria-label="Status" />
    <AppButton type="submit" variant="secondary"><Search class="size-5" /> Cari</AppButton>
  </form>
  <DataTable
    dense :columns="COLUMNS" :rows="list.rows.value" :meta="list.meta.value" :sort="list.sort.value" :loading="list.loading.value"
    empty-text="Tidak ada staf yang cocok." @sort="list.toggleSort" @page="list.goto" @per-page="list.setPerPage"
  >
    <template #cell-name="{ row: s }">{{ s.name }}<span class="block text-sm text-muted">{{ s.email }}</span></template>
    <template #cell-role="{ row: s }">
      <AppSelect
        :model-value="s.role_id"
        :options="roles"
        :aria-label="`Peran ${s.email}`"
        class="!min-h-11 !w-auto"
        @update:model-value="(v) => act(() => adminApi.updateStaff(s.id, { role_id: Number(v) }), 'Peran diperbarui.')"
      />
    </template>
    <template #cell-status="{ row: s }"><AppBadge :tone="s.status === 'active' ? 'ok' : 'muted'">{{ s.status === 'active' ? 'aktif' : 'dicabut' }}</AppBadge></template>
    <template #cell-last_reverified_at="{ row: s }"><span class="font-mono">{{ dateTime(s.last_reverified_at) }}</span></template>
    <template #cell-actions="{ row: s }">
      <span class="flex flex-wrap gap-1">
        <AppButton variant="ghost" :disabled="busy" @click="act(() => adminApi.resetStaffPassword(s.id), 'Password sementara dibuat.')">
          <KeyRound class="size-5" /> {{ s.has_password ? 'Reset password' : 'Buat password' }}
        </AppButton>
        <AppButton
          :variant="s.status === 'active' ? 'ghost' : 'secondary'"
          :disabled="busy"
          @click="act(() => adminApi.updateStaff(s.id, { status: s.status === 'active' ? 'revoked' : 'active' }), 'Akses staf diperbarui.')"
        >{{ s.status === 'active' ? 'Cabut akses' : 'Aktifkan' }}</AppButton>
      </span>
    </template>
  </DataTable>
</template>
