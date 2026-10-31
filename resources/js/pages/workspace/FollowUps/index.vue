<script setup>
// LD-05 — daftar "Terlambat / Hari ini / Mendatang / Selesai" (server-side). Selesaikan atau tunda dari baris.
import { computed } from 'vue'
import { Check, MessageCircle, Phone } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import StageBadge from '@/components/common/StageBadge/index.vue'
import { remindersApi } from '@/api/workspace'
import { useAction } from '@/composables/useAsync'
import { usePagedList } from '@/composables/usePagedList'
import { dateTime, relative } from '@/lib/format'

const list = usePagedList(remindersApi.list, { due: 'overdue' }, { perPage: 25 })
const { busy, run } = useAction()
const counts = computed(() => list.meta.value?.counts ?? { overdue: 0, today: 0 })
const TABS = computed(() => [
  { value: 'overdue', label: `Terlambat${counts.value.overdue ? ` (${counts.value.overdue})` : ''}` },
  { value: 'today', label: `Hari ini${counts.value.today ? ` (${counts.value.today})` : ''}` },
  { value: 'upcoming', label: 'Mendatang' },
  { value: 'done', label: 'Selesai' },
])
const COLUMNS = computed(() => [
  { key: 'lead', label: 'Lead' },
  { key: 'note', label: 'Catatan' },
  { key: 'due_at', label: list.filters.due === 'done' ? 'Jadwal' : 'Jatuh tempo', sortable: true },
  { key: 'actions', label: '' },
])
async function act(fn, message) {
  if (await run(fn, message).catch(() => null)) list.reload()
}
const tomorrow = (r) => new Date(Math.max(Date.now(), Date.parse(r.due_at)) + 86400000).toISOString()
const wa = (phone) => (phone ? `https://wa.me/${phone.replace(/\D/g, '')}` : null)
</script>

<template>
  <PageHeader eyebrow="Pengingat" title="Follow-up" />
  <AppSegmented v-model="list.filters.due" label="Tampilkan" :options="TABS" class="flex-wrap" />

  <DataTable
    :columns="COLUMNS" :rows="list.rows.value" :meta="list.meta.value" :sort="list.sort.value" :loading="list.loading.value"
    :empty-text="list.filters.due === 'overdue' ? 'Tidak ada follow-up terlambat. Mantap!' : 'Tidak ada pengingat di sini.'"
    @sort="list.toggleSort" @page="list.goto" @per-page="list.setPerPage"
  >
    <template #cell-lead="{ row: r }">
      <RouterLink v-if="r.lead" :to="`/connections/${r.lead.id}`" class="font-semibold">{{ r.lead.name }}</RouterLink>
      <span v-if="r.lead?.company" class="block text-sm text-muted">{{ r.lead.company }}</span>
      <StageBadge v-if="r.lead?.stage" :stage="r.lead.stage" class="mt-1" />
    </template>
    <template #cell-note="{ row: r }"><span :class="r.note ? '' : 'text-muted'">{{ r.note || (r.is_auto ? 'Follow-up otomatis' : '—') }}</span></template>
    <template #cell-due_at="{ row: r }">
      <span :class="r.overdue ? 'font-medium text-danger' : ''">{{ relative(r.due_at) }}</span>
      <span class="block font-mono text-xs text-muted">{{ dateTime(r.due_at) }}</span>
    </template>
    <template #cell-actions="{ row: r }">
      <span class="flex flex-wrap justify-end gap-1">
        <AppButton v-if="wa(r.lead?.phone)" variant="ghost" icon :href="wa(r.lead.phone)" target="_blank" rel="noopener" :aria-label="`WhatsApp ${r.lead.name}`"><MessageCircle class="size-5" /></AppButton>
        <AppButton v-if="r.lead?.phone" variant="ghost" icon :href="`tel:${r.lead.phone}`" :aria-label="`Telepon ${r.lead.name}`"><Phone class="size-5" /></AppButton>
        <template v-if="!r.done_at">
          <AppButton variant="ghost" :disabled="busy" @click="act(() => remindersApi.update(r.id, { due_at: tomorrow(r) }), 'Ditunda 1 hari')">Tunda</AppButton>
          <AppButton variant="secondary" :disabled="busy" @click="act(() => remindersApi.update(r.id, { done: true }), 'Follow-up selesai')"><Check class="size-5" /> Selesai</AppButton>
        </template>
        <AppButton v-else variant="ghost" :disabled="busy" @click="act(() => remindersApi.update(r.id, { done: false }), 'Dibuka lagi')">Buka lagi</AppButton>
      </span>
    </template>
  </DataTable>
</template>
