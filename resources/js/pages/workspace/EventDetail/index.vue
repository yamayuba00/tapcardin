<script setup>
// U-09 — ringkasan event (tap, pengunjung, simpan kontak, koneksi per tahap) + daftar koneksi event (server-side) + ekspor CSV.
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CreditCard, Download, Pencil, Power, Radio, Save, Trash2 } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
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
// kartu yang dibawa ke event: pilihan lokal, disimpan dengan tombol
const picked = ref(null) // token[] saat sedang diubah
const cardOptions = computed(() => (e.value?.available_cards ?? []).map((c) => ({ value: c.token, label: c.label, description: c.form === 'digital' ? 'Kartu digital' : c.short_code })))
const cardsDirty = computed(() => picked.value !== null && [...picked.value].sort().join() !== (e.value?.cards ?? []).map((c) => c.token).sort().join())
async function saveCards() {
  if (await act(() => eventsApi.cards(id, picked.value), 'Kartu event disimpan')) picked.value = null
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
      <KpiCard label="Koneksi" :value="num(e.summary.connections)" hint="Tukar Kontak, formulir, scan, & manual" />
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
        <AppPanel title="Kartu di event ini">
          <div class="grid gap-3">
            <p class="text-sm text-muted">Selama event berlangsung, tap, Tukar Kontak, dan formulir dari kartu ini otomatis tercatat ke event — tanpa perlu menyalakan Mode Event.</p>
            <ul v-if="e.cards.length" class="grid gap-1">
              <li v-for="c in e.cards" :key="c.token">
                <RouterLink :to="`/cards/${c.token}`" class="flex min-h-10 items-center gap-2 rounded-sm px-1 text-sm no-underline hover:bg-surface-2">
                  <CreditCard class="size-4 flex-none text-accent-text" aria-hidden="true" />
                  <span class="min-w-0 flex-1 truncate font-medium text-fg">{{ c.label }}</span>
                  <span class="font-mono text-xs text-muted">{{ c.form === 'digital' ? 'digital' : c.short_code }}</span>
                </RouterLink>
              </li>
            </ul>
            <p v-else class="text-sm text-muted">Belum ada kartu yang dibawa ke event ini.</p>
            <AppSelect
              :model-value="picked ?? e.cards.map((c) => c.token)" :options="cardOptions" multiple searchable placeholder="Pilih kartu…" aria-label="Kartu di event ini"
              empty-text="Belum ada kartu aktif." @update:model-value="(v) => (picked = v)"
            />
            <div v-if="cardsDirty" class="flex gap-2">
              <AppButton :loading="busy" @click="saveCards"><Save class="size-4" /> Simpan kartu</AppButton>
              <AppButton variant="ghost" @click="picked = null">Batal</AppButton>
            </div>
          </div>
        </AppPanel>
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
