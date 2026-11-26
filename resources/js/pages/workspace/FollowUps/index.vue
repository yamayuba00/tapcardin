<script setup>
// LD-05 — daftar "Terlambat / Hari ini / Mendatang / Selesai" (server-side). Selesaikan, tunda, buka lagi, atau hapus dari baris.
import { computed, ref } from 'vue'
import { Check, MessageCircle, Phone, Trash2 } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import StageBadge from '@/components/common/StageBadge/index.vue'
import { remindersApi } from '@/api/workspace'
import { useAction } from '@/composables/useAsync'
import { usePagedList } from '@/composables/usePagedList'
import { FOLLOWUP_WA_TEMPLATE, followupWhatsAppUrl } from '@/lib/followupWhatsApp'
import { dateTime, relative } from '@/lib/format'

const list = usePagedList(remindersApi.list, { due: 'overdue', member: '' }, { perPage: 25 })
const { run } = useAction()
const busyId = ref(null)
const counts = computed(() => list.meta.value?.counts ?? { overdue: 0, today: 0, upcoming: 0, done: 0 })
// tampilan tim (owner/admin): ada rekap per anggota dan filter penanggung jawab; 'none' = belum ditugaskan
const isTeam = computed(() => Array.isArray(list.meta.value?.members))
const team = computed(() => list.meta.value?.team ?? [])
const memberOptions = computed(() => [{ value: '', label: 'Semua anggota' }, { value: 'none', label: 'Belum ditugaskan' }, ...team.value.map((m) => ({ value: m.id, label: m.name }))])
const memberRows = computed(() => list.meta.value?.members ?? [])
const TABS = computed(() => [
  { value: 'overdue', label: 'Terlambat', count: counts.value.overdue, tone: 'text-danger' },
  { value: 'today', label: 'Hari ini', count: counts.value.today },
  { value: 'upcoming', label: 'Mendatang', count: counts.value.upcoming },
  { value: 'done', label: 'Selesai', count: counts.value.done },
])
// teks kosong per tab beserta langkah berikutnya
const EMPTY = {
  overdue: { text: 'Tidak ada follow-up terlambat. Mantap!', action: 'Buka Daftar koneksi', to: '/connections' },
  today: { text: 'Tidak ada follow-up untuk hari ini.', action: 'Buka Ringkasan lead', to: '/connections/dashboard' },
  upcoming: { text: 'Belum ada follow-up terjadwal. Pengingat dibuat dari detail koneksi.', action: 'Buka Daftar koneksi', to: '/connections' },
  done: { text: 'Belum ada follow-up yang diselesaikan.', action: 'Buka Daftar koneksi', to: '/connections' },
}
const empty = computed(() => EMPTY[list.filters.due] ?? EMPTY.overdue)
const COLUMNS = computed(() => [
  { key: 'lead', label: 'Lead', class: 'min-w-[12rem]' },
  ...(isTeam.value ? [{ key: 'assignee', label: 'Penanggung jawab', class: 'whitespace-nowrap' }] : []),
  { key: 'note', label: 'Catatan' },
  { key: 'due_at', label: list.filters.due === 'done' ? 'Jadwal' : 'Jatuh tempo', sortable: true, class: 'whitespace-nowrap' },
  { key: 'actions', label: '', class: 'text-right whitespace-nowrap' },
])

// Tunda/selesai/buka lagi/hapus: baris yang keluar dari tab ini langsung hilang (optimistik), lalu daftar
// dan jumlah tiap tab dimuat ulang dari server; bila gagal, pemuatan ulang mengembalikan baris itu.
async function act(row, fn, message, leavesTab = true) {
  busyId.value = row.id
  if (leavesTab && list.rows.value) list.rows.value = list.rows.value.filter((x) => x.id !== row.id)
  try {
    await run(fn, message)
  } catch {
    // galat sudah ditampilkan oleh run()
  } finally {
    busyId.value = null
    list.reload()
  }
}
function remove(row) {
  if (window.confirm('Hapus pengingat ini? Tindakan ini tidak bisa dibatalkan.')) {
    act(row, () => remindersApi.remove(row.id), 'Pengingat dihapus')
  }
}
const tomorrow = (r) => new Date(Math.max(Date.now(), Date.parse(r.due_at)) + 86400000).toISOString()
const waUrl = (r) => followupWhatsAppUrl(r.lead?.phone, FOLLOWUP_WA_TEMPLATE)
</script>

<template>
  <PageHeader eyebrow="Pengingat" title="Follow-up" />

  <!-- ringkasan: jumlah per tab; ketuk untuk mengganti tab -->
  <div class="grid grid-cols-2 gap-2 sm:grid-cols-4" role="group" aria-label="Tampilkan">
    <button
      v-for="t in TABS" :key="t.value" type="button" :aria-pressed="list.filters.due === t.value"
      class="grid cursor-pointer gap-0.5 rounded-md border border-line bg-surface px-3 py-2 text-left hover:bg-surface-2 aria-pressed:border-accent aria-pressed:bg-surface-2"
      @click="list.filters.due = t.value"
    >
      <span class="text-sm text-muted">{{ t.label }}</span>
      <span class="font-mono text-xl font-semibold" :class="t.tone">{{ t.count }}</span>
    </button>
  </div>

  <template v-if="isTeam">
    <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_220px] sm:items-end">
      <div class="flex flex-wrap gap-2" aria-label="Rekap per anggota">
        <button
          v-for="m in memberRows" :key="m.id ?? 'none'" type="button" :aria-pressed="(list.filters.member ?? '') === (m.id ?? 'none')"
          class="grid cursor-pointer gap-0.5 rounded-md border border-line px-3 py-2 text-left text-sm hover:bg-surface-2 aria-pressed:border-accent aria-pressed:bg-surface-2"
          @click="list.filters.member = m.id ?? 'none'"
        >
          <span class="font-medium">{{ m.name }}</span>
          <span class="font-mono text-xs text-muted">{{ m.overdue }} terlambat · {{ m.today }} hari ini · {{ m.open }} terbuka</span>
        </button>
      </div>
      <AppSelect v-model="list.filters.member" :options="memberOptions" searchable placeholder="Semua anggota" aria-label="Filter penanggung jawab" />
    </div>
  </template>

  <DataTable
    :columns="COLUMNS" :rows="list.rows.value" :meta="list.meta.value" :sort="list.sort.value" :loading="list.loading.value"
    :empty-text="empty.text"
    @sort="list.toggleSort" @page="list.goto" @per-page="list.setPerPage"
  >
    <template #empty>
      <div class="grid justify-items-center gap-2 py-2">
        <p>{{ empty.text }}</p>
        <RouterLink :to="empty.to" class="text-sm font-semibold text-accent-text underline underline-offset-4">{{ empty.action }}</RouterLink>
      </div>
    </template>
    <template #cell-lead="{ row: r }">
      <template v-if="r.lead">
        <!-- lead sudah dihapus: nama tampil sebagai teks biasa, tanpa tautan -->
        <RouterLink v-if="!r.lead.deleted" :to="`/connections/${r.lead.id}`" class="font-semibold">{{ r.lead.name }}</RouterLink>
        <span v-else class="font-semibold">{{ r.lead.name }}</span>
        <span v-if="r.lead.deleted" class="block text-xs text-muted">Lead sudah dihapus</span>
        <span v-if="r.lead.company" class="block text-sm text-muted">{{ r.lead.company }}</span>
        <StageBadge v-if="r.lead.stage" :stage="r.lead.stage" class="mt-1" />
      </template>
      <span v-else class="text-muted">Lead tidak tersedia</span>
    </template>
    <template #cell-assignee="{ row: r }"><span :class="r.lead?.assignee ? '' : 'text-muted'">{{ r.lead?.assignee?.name ?? 'Belum ditugaskan' }}</span></template>
    <template #cell-note="{ row: r }"><span :class="r.note ? '' : 'text-muted'">{{ r.note || (r.is_auto ? 'Follow-up otomatis' : '—') }}</span></template>
    <template #cell-due_at="{ row: r }">
      <span :class="r.overdue ? 'font-medium text-danger' : ''">{{ relative(r.due_at) }}</span>
      <span class="block font-mono text-xs text-muted">{{ dateTime(r.due_at) }}</span>
    </template>
    <template #cell-actions="{ row: r }">
      <div class="flex flex-wrap items-center justify-end gap-1 sm:flex-nowrap">
        <span v-if="busyId === r.id" class="px-2 text-sm text-muted" role="status">Menyimpan…</span>
        <template v-else>
          <AppButton v-if="waUrl(r)" variant="ghost" icon :href="waUrl(r)" target="_blank" rel="noopener" :aria-label="`Kirim WhatsApp ke ${r.lead.name}`" title="Kirim WhatsApp"><MessageCircle class="size-5" /></AppButton>
          <AppButton v-if="r.lead?.phone" variant="ghost" icon :href="`tel:${r.lead.phone}`" :aria-label="`Telepon ${r.lead.name}`" title="Telepon"><Phone class="size-5" /></AppButton>
          <template v-if="!r.done_at">
            <AppButton variant="ghost" class="shrink-0" @click="act(r, () => remindersApi.update(r.id, { due_at: tomorrow(r) }), 'Ditunda 1 hari', list.filters.due !== 'upcoming')">Tunda 1 hari</AppButton>
            <AppButton variant="secondary" class="shrink-0" @click="act(r, () => remindersApi.update(r.id, { done: true }), 'Follow-up selesai')"><Check class="size-5" /> Selesai</AppButton>
          </template>
          <AppButton v-else variant="ghost" class="shrink-0" @click="act(r, () => remindersApi.update(r.id, { done: false }), 'Dibuka lagi')">Buka lagi</AppButton>
          <AppButton variant="ghost" class="shrink-0 text-danger" :aria-label="`Hapus pengingat ${r.lead?.name ?? ''}`" @click="remove(r)"><Trash2 class="size-5" /> Hapus</AppButton>
        </template>
      </div>
    </template>
  </DataTable>
</template>
