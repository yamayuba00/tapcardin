<script setup>
// U-09 / AFD F-10 — Mode Event: buat event, aktifkan "Saya sedang di event ini" → tap & koneksi diberi label event.
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { CalendarPlus, Radio } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import EventForm from '@/components/events/EventForm/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { eventsApi } from '@/api/workspace'
import { useAction } from '@/composables/useAsync'
import { usePagedList } from '@/composables/usePagedList'
import { dateTime } from '@/lib/format'

const router = useRouter()
const list = usePagedList(eventsApi.list, { q: '', when: '' }, { sort: '-starts_at', perPage: 10 })
const { busy, run } = useAction()
const active = computed(() => list.meta.value?.active ?? null)
const STATUS = { live: ['ok', 'Berlangsung'], upcoming: ['info', 'Akan datang'], past: ['muted', 'Selesai'] }
const COLUMNS = [
  { key: 'name', label: 'Event', sortable: true }, { key: 'starts_at', label: 'Waktu', sortable: true },
  { key: 'status', label: 'Status' }, { key: 'cards', label: 'Kartu', align: 'right' }, { key: 'connections', label: 'Koneksi', sortable: true, align: 'right' },
]

const creating = ref(false)
const errors = ref({})
async function create(body) {
  errors.value = {}
  try {
    const res = await run(() => eventsApi.create(body), body.activate ? 'Event dibuat & Mode Event aktif' : 'Event dibuat')
    router.push(`/events/${res.data.id}`)
  } catch (e) { errors.value = e.fields || {} }
}
async function stop() {
  if (await run(() => eventsApi.deactivate(), 'Mode Event dimatikan').catch(() => null)) list.reload()
}
</script>

<template>
  <PageHeader eyebrow="Mode Event" title="Event">
    <AppButton @click="creating = true"><CalendarPlus class="size-5" /> Event baru</AppButton>
  </PageHeader>

  <div v-if="active" class="grid grid-cols-[auto_minmax(0,1fr)] items-start gap-x-3 gap-y-3 rounded-md border border-accent bg-surface p-4 sm:flex sm:flex-wrap sm:items-center sm:py-3">
    <Radio class="mt-0.5 size-5 animate-tap-pulse text-accent-text" aria-hidden="true" />
    <span class="min-w-0 flex-1">
      <strong>Mode Event aktif: {{ active.name }}</strong>
      <span class="block text-sm text-muted">Setiap tap & koneksi baru diberi label event ini sampai {{ dateTime(active.ends_at) }}.</span>
    </span>
    <div class="col-span-2 grid grid-cols-2 gap-2 sm:col-span-1 sm:flex">
      <AppButton variant="secondary" :to="`/events/${active.id}`">Lihat ringkasan</AppButton>
      <AppButton variant="ghost" :loading="busy" @click="stop">Matikan</AppButton>
    </div>
  </div>
  <p v-else class="text-sm text-muted">Sedang di pameran, seminar, atau networking? Aktifkan Mode Event agar semua koneksi dari acara itu terkumpul dan bisa diekspor sekaligus.</p>

  <form class="grid grid-cols-[minmax(0,1fr)_auto] gap-2 sm:flex sm:flex-wrap" @submit.prevent="list.search">
    <AppInput v-model="list.filters.q" type="search" placeholder="Cari nama atau lokasi event" aria-label="Cari event" class="sm:max-w-xs" />
    <AppButton type="submit" variant="secondary" class="sm:order-last">Cari</AppButton>
    <AppSelect v-model="list.filters.when" placeholder="Semua event" :options="[{ value: 'upcoming', label: 'Berlangsung & akan datang' }, { value: 'past', label: 'Sudah selesai' }]" class="col-span-2 sm:col-span-1 sm:!w-auto" aria-label="Waktu" />
  </form>

  <DataTable
    :columns="COLUMNS" :rows="list.rows.value" :meta="list.meta.value" :sort="list.sort.value" :loading="list.loading.value"
    empty-text="Belum ada event." @sort="list.toggleSort" @page="list.goto" @per-page="list.setPerPage"
  >
    <template #cell-name="{ row: e }">
      <RouterLink :to="`/events/${e.id}`" class="font-semibold">{{ e.name }}</RouterLink>
      <AppBadge v-if="e.is_active" tone="ok" class="ml-2">aktif</AppBadge>
      <span v-if="e.location" class="block text-sm text-muted">{{ e.location }}</span>
    </template>
    <template #cell-starts_at="{ row: e }"><span class="font-mono text-sm">{{ dateTime(e.starts_at) }}</span><span class="block font-mono text-xs text-muted">s.d. {{ dateTime(e.ends_at) }}</span></template>
    <template #cell-status="{ row: e }"><AppBadge :tone="STATUS[e.status][0]">{{ STATUS[e.status][1] }}</AppBadge></template>
    <template #cell-cards="{ row: e }"><span class="num">{{ e.cards_count ?? 0 }}</span></template>
    <template #cell-connections="{ row: e }"><span class="num">{{ e.connections_count }}</span></template>
  </DataTable>

  <AppModal v-if="creating" title="Event baru" description="Kumpulkan semua koneksi dari satu acara agar mudah ditindaklanjuti dan diekspor." @close="creating = false">
    <EventForm id="event-create" with-activate :errors="errors" @submit="create" />
    <template #footer>
      <AppButton variant="secondary" @click="creating = false">Batal</AppButton>
      <AppButton type="submit" form="event-create" :loading="busy"><CalendarPlus class="size-5" /> Buat event</AppButton>
    </template>
  </AppModal>
</template>
