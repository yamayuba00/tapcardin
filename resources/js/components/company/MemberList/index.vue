<script setup>
// Karyawan: tambah dengan email akun terdaftar, ubah peran, nonaktifkan (kartunya kembali ke stok) / aktifkan lagi.
import { ref } from 'vue'
import { Mail, Plus, Search } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import { companyApi } from '@/api/workspace'
import { useAction } from '@/composables/useAsync'
import { usePagedList } from '@/composables/usePagedList'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const list = usePagedList(companyApi.members, { q: '', status: '' }, { sort: 'name' })
const { busy, run } = useAction()
const roles = () => (list.meta.value?.roles ?? []).map((r) => ({ value: r.key, label: r.name }))
const COLUMNS = [{ key: 'name', label: 'Karyawan', sortable: true }, { key: 'role', label: 'Peran' }, { key: 'status', label: 'Status', sortable: true }, { key: 'actions' }]
const editable = (m) => !m.is_owner && m.user_id !== auth.me?.id

const form = ref({ email: '', role: 'member' })
const errors = ref({})
async function add() {
  errors.value = {}
  try {
    await run(() => companyApi.addMember(form.value.email, form.value.role), 'Karyawan ditambahkan.')
    form.value.email = ''
    list.reload()
  } catch (e) { errors.value = e.fields ?? {} }
}
const replace = (m) => { list.rows.value = list.rows.value.map((x) => (x.user_id === m.user_id ? m : x)) }
async function setRole(m, role) {
  const res = await run(() => companyApi.setRole(m.user_id, role), 'Peran diperbarui.').catch(() => null)
  if (res) replace(res.data)
}
const leaving = ref(null)
async function deactivate() {
  const res = await run(() => companyApi.deactivate(leaving.value.user_id), 'Karyawan dinonaktifkan.').catch(() => null)
  if (res) replace(res.data)
  leaving.value = null
}
async function reactivate(m) {
  const res = await run(() => companyApi.reactivate(m.user_id), 'Karyawan diaktifkan kembali.').catch(() => null)
  if (res) replace(res.data)
}

// undangan lewat email: karyawan yang belum punya akun juga bisa diundang
const invites = ref([])
const inviteForm = ref({ email: '', role: 'member' })
const inviteErrors = ref({})
async function loadInvites() {
  if (auth.can('members.invite')) invites.value = (await companyApi.invites().catch(() => ({ data: [] }))).data
}
async function sendInvite() {
  inviteErrors.value = {}
  try {
    await run(() => companyApi.invite(inviteForm.value.email, inviteForm.value.role), 'Undangan dikirim.')
    inviteForm.value.email = ''
    loadInvites()
  } catch (e) { inviteErrors.value = e.fields ?? {} }
}
async function resendInvite(i) {
  await run(() => companyApi.resendInvite(i.id), 'Undangan dikirim ulang.').catch(() => null)
  loadInvites()
}
async function revokeInvite(i) {
  await run(() => companyApi.revokeInvite(i.id), 'Undangan dibatalkan.').catch(() => null)
  loadInvites()
}
loadInvites()
</script>

<template>
  <AppPanel v-if="auth.can('members.invite')" title="Tambah karyawan">
    <form class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_14rem_auto] sm:items-end" @submit.prevent="add">
      <AppField label="Email akun TapCard" :error="errors.email?.[0]">
        <AppInput v-model="form.email" type="email" required maxlength="190" placeholder="nama@perusahaan.com" :invalid="!!errors.email" />
      </AppField>
      <AppField label="Peran"><AppSelect v-model="form.role" :options="roles()" /></AppField>
      <AppButton type="submit" :loading="busy"><Plus class="size-5" /> Tambah</AppButton>
    </form>
    <p class="mt-3 text-sm text-muted">Karyawan harus sudah pernah login ke TapCard dengan email ini. Belum punya akun? Gunakan undangan email di bawah.</p>
  </AppPanel>

  <AppPanel v-if="auth.can('members.invite')" title="Undang lewat email">
    <form class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_14rem_auto] sm:items-end" @submit.prevent="sendInvite">
      <AppField label="Email karyawan" :error="inviteErrors.email?.[0]">
        <AppInput v-model.trim="inviteForm.email" type="email" required maxlength="190" placeholder="nama@perusahaan.com" :invalid="!!inviteErrors.email" />
      </AppField>
      <AppField label="Peran"><AppSelect v-model="inviteForm.role" :options="roles()" /></AppField>
      <AppButton type="submit" :loading="busy"><Mail class="size-5" /> Undang</AppButton>
    </form>
    <p class="mt-3 text-sm text-muted">Karyawan menerima tautan aktivasi yang berlaku 72 jam, lalu membuat kata sandinya sendiri. Tidak perlu punya akun TapCard sebelumnya.</p>
    <ul v-if="invites.length" class="mt-4 grid gap-2" aria-label="Undangan menunggu">
      <li v-for="i in invites" :key="i.id" class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-line px-3 py-2 text-sm">
        <span class="min-w-0 break-all">{{ i.email }} <span class="text-muted">· {{ i.role_name }}</span></span>
        <span class="flex flex-wrap items-center gap-2">
          <AppBadge :tone="i.status === 'pending' ? 'warn' : 'muted'">{{ i.status === 'pending' ? 'Diundang' : 'Kedaluwarsa' }}</AppBadge>
          <AppButton variant="ghost" :disabled="busy" @click="resendInvite(i)">Kirim ulang</AppButton>
          <AppButton variant="ghost" :disabled="busy" @click="revokeInvite(i)">Batalkan</AppButton>
        </span>
      </li>
    </ul>
  </AppPanel>

  <form class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap" @submit.prevent="list.search">
    <AppInput v-model="list.filters.q" type="search" placeholder="Nama atau email" aria-label="Cari karyawan" class="col-span-2 sm:max-w-xs" />
    <AppSelect v-model="list.filters.status" placeholder="Semua status" :options="[{ value: 'active', label: 'Aktif' }, { value: 'removed', label: 'Nonaktif' }]" class="sm:!w-56" aria-label="Status" />
    <AppButton type="submit" variant="secondary"><Search class="size-5" /> Cari</AppButton>
  </form>

  <DataTable
    dense row-key="user_id" :columns="COLUMNS" :rows="list.rows.value" :meta="list.meta.value" :sort="list.sort.value" :loading="list.loading.value"
    empty-text="Belum ada karyawan." @sort="list.toggleSort" @page="list.goto" @per-page="list.setPerPage"
  >
    <template #cell-name="{ row: m }">{{ m.name }}<span class="block text-sm text-muted">{{ m.email }}</span></template>
    <template #cell-role="{ row: m }">
      <AppBadge v-if="m.is_owner" tone="outline">Owner</AppBadge>
      <AppSelect
        v-else-if="auth.can('roles.manage') && editable(m) && m.status === 'active'"
        :model-value="m.role" :options="roles()" :aria-label="`Peran ${m.email}`" class="!min-h-11 !w-48"
        @update:model-value="(v) => setRole(m, v)"
      />
      <span v-else>{{ m.role_name }}</span>
    </template>
    <template #cell-status="{ row: m }"><AppBadge :tone="m.status === 'active' ? 'ok' : 'muted'">{{ m.status === 'active' ? 'aktif' : 'nonaktif' }}</AppBadge></template>
    <template #cell-actions="{ row: m }">
      <template v-if="auth.can('members.remove') && editable(m)">
        <AppButton v-if="m.status === 'active'" variant="ghost" :disabled="busy" @click="leaving = m">Nonaktifkan</AppButton>
        <AppButton v-else variant="ghost" :disabled="busy" @click="reactivate(m)">Aktifkan kembali</AppButton>
      </template>
    </template>
  </DataTable>

  <AppModal v-if="leaving" size="sm" title="Nonaktifkan karyawan?" :description="`${leaving.name} tidak bisa lagi masuk ke workspace ini. Kartu yang dipegang atau ditugaskan kepadanya kembali ke stok perusahaan.`" @close="leaving = null">
    <template #footer>
      <AppButton variant="secondary" @click="leaving = null">Batal</AppButton>
      <AppButton variant="danger" :loading="busy" @click="deactivate">Nonaktifkan</AppButton>
    </template>
  </AppModal>
</template>
