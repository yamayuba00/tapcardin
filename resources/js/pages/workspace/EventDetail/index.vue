<script setup>
// U-09 — ringkasan event (tap, pengunjung, simpan kontak, koneksi per tahap) + daftar koneksi event (server-side) + ekspor CSV.
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Download, Pencil, Power, Radio, Trash2 } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import AppToggle from '@/components/ui/AppToggle/index.vue'
import BarList from '@/components/charts/BarList/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import KpiCard from '@/components/common/KpiCard/index.vue'
import EventForm from '@/components/events/EventForm/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import StageBadge from '@/components/common/StageBadge/index.vue'
import { eventsApi, leadsApi } from '@/api/workspace'
import { useAction, useAsync } from '@/composables/useAsync'
import { usePagedList } from '@/composables/usePagedList'
import { dateTime, num, relative } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const id = route.params.id
const { data: e } = useAsync(() => eventsApi.get(id))
const { busy, run } = useAction()
const leads = usePagedList((p) => leadsApi.list({ ...p, event: id }), {}, { url: false, sort: '-created_at', perPage: 10 })
const removing = ref(false)
const editing = ref(false)
const errors = ref({})
async function saveEdit(body) {
  errors.value = {}
  try {
    e.value = (await run(() => eventsApi.update(id, body), 'Event diperbarui')).data
    editing.value = false
  } catch (err) { errors.value = err.fields || {} }
}
const COLUMNS = [{ key: 'name', label: 'Nama', sortable: true }, { key: 'stage', label: 'Tahap', sortable: true }, { key: 'created_at', label: 'Ditambahkan', sortable: true }]
const STATUS = { live: ['ok', 'Berlangsung'], upcoming: ['info', 'Akan datang'], past: ['muted', 'Selesai'] }
const exportHref = computed(() => `${leadsApi.exportUrl}?event=${id}`)

async function act(fn, message) {
  const res = await run(fn, message).catch(() => null)
  if (res?.data?.summary) e.value = res.data
  return res
}
async function deactivate() {
  if (await act(() => eventsApi.deactivate(), 'Mode Event dimatikan')) e.value = { ...e.value, is_active: false }
}
async function destroy() {
  if (await act(() => eventsApi.remove(id), 'Event dihapus. Koneksinya tetap tersimpan.')) router.push('/events')
}
</script>

<template>
  <AppSkeleton v-if="!e" class="!h-60" />
  <template v-else>
    <PageHeader :title="e.name" back="/events" back-label="Event">
      <template #meta>
        <div class="flex flex-wrap items-center gap-2 text-sm text-muted">
          <AppBadge :tone="STATUS[e.status][0]">{{ STATUS[e.status][1] }}</AppBadge>
          <span>{{ dateTime(e.starts_at) }} – {{ dateTime(e.ends_at) }}</span>
          <span v-if="e.location">· {{ e.location }}</span>
        </div>
      </template>
      <AppButton variant="ghost" @click="editing = true"><Pencil class="size-5" /> Ubah</AppButton>
      <AppButton v-if="auth.can('leads.export.*')" variant="secondary" :href="exportHref"><Download class="size-5" /> Ekspor koneksi</AppButton>
      <AppButton v-if="e.is_active" variant="secondary" :loading="busy" @click="deactivate"><Power class="size-5" /> Matikan Mode Event</AppButton>
      <AppButton v-else-if="e.status !== 'past'" :loading="busy" @click="act(() => eventsApi.activate(id), 'Mode Event aktif')"><Radio class="size-5" /> Saya sedang di sini</AppButton>
    </PageHeader>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <KpiCard label="Tap" :value="num(e.summary.taps)" :hint="`${num(e.summary.visitors)} pengunjung unik`" />
      <KpiCard label="Simpan kontak" :value="num(e.summary.saved)" :hint="e.summary.save_rate === null ? 'Belum ada pengunjung' : `${e.summary.save_rate}% dari pengunjung`" />
      <KpiCard label="Koneksi" :value="num(e.summary.connections)" hint="Tukar Kontak, scan, & input manual" />
      <KpiCard label="Terakhir" :value="e.status === 'past' ? 'Selesai' : relative(e.ends_at)" :hint="e.status === 'past' ? `Berakhir ${relative(e.ends_at)}` : 'sampai event berakhir'" />
    </div>

    <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
      <div class="grid gap-3">
        <h2 class="text-base font-semibold">Koneksi dari event ini</h2>
        <DataTable
          :columns="COLUMNS" :rows="leads.rows.value" :meta="leads.meta.value" :sort="leads.sort.value" :loading="leads.loading.value"
          empty-text="Belum ada koneksi dari event ini." @sort="leads.toggleSort" @page="leads.goto" @per-page="leads.setPerPage"
        >
          <template #cell-name="{ row: c }"><RouterLink :to="`/connections/${c.id}`" class="font-semibold">{{ c.name }}</RouterLink><span class="block text-sm text-muted">{{ c.company || c.email || c.phone }}</span></template>
          <template #cell-stage="{ row: c }"><StageBadge :stage="c.stage" /></template>
          <template #cell-created_at="{ row: c }">{{ relative(c.created_at) }}</template>
        </DataTable>
      </div>
      <div class="grid gap-4">
        <AppPanel title="Koneksi per tahap"><BarList :items="e.by_stage" label="Koneksi event per tahap" empty="Belum ada koneksi." /></AppPanel>
        <AppPanel title="Pengaturan">
          <div class="grid gap-4">
            <AppToggle :model-value="e.show_on_profile" @update:model-value="(v) => act(() => eventsApi.update(id, { show_on_profile: v }), 'Disimpan')">Tampilkan "Bertemu di {{ e.name }}" di bio link</AppToggle>
            <div><AppButton variant="ghost" @click="removing = true"><Trash2 class="size-5" /> Hapus event</AppButton></div>
          </div>
        </AppPanel>
      </div>
    </div>

    <AppModal v-if="editing" title="Ubah event" @close="editing = false">
      <EventForm id="event-edit" :initial="e" :errors="errors" @submit="saveEdit" />
      <template #footer>
        <AppButton variant="secondary" @click="editing = false">Batal</AppButton>
        <AppButton type="submit" form="event-edit" :loading="busy">Simpan</AppButton>
      </template>
    </AppModal>
    <AppModal v-if="removing" size="sm" title="Hapus event?" description="Koneksi & tap dari event ini tetap tersimpan, hanya label event-nya yang dilepas." @close="removing = false">
      <template #footer>
        <AppButton variant="secondary" @click="removing = false">Batal</AppButton>
        <AppButton variant="danger" :loading="busy" @click="destroy"><Trash2 class="size-5" /> Hapus event</AppButton>
      </template>
    </AppModal>
  </template>
</template>
