<script setup>
// Log pengiriman satu endpoint: filter status & event, detail permintaan/balasan, kirim ulang.
import { ref, watch } from 'vue'
import { RefreshCw, RotateCcw } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import { webhooksApi } from '@/api/workspace'
import { useAction } from '@/composables/useAsync'
import { usePagedList } from '@/composables/usePagedList'
import { dateTime } from '@/lib/format'

const props = defineProps({ endpoint: { type: Object, required: true }, events: { type: Array, required: true } })
const list = usePagedList((p) => webhooksApi.deliveries(props.endpoint.id, p), { status: '', type: '' }, { url: false, sort: '-created_at', perPage: 25 })
watch(() => props.endpoint.id, () => list.search())
const { busy, run } = useAction()

const STATUS = {
  success: ['ok', 'Sukses'], failed: ['danger', 'Gagal'], retrying: ['warn', 'Dicoba ulang'], pending: ['muted', 'Menunggu'],
}
const COLUMNS = [
  { key: 'created_at', label: 'Waktu', sortable: true }, { key: 'type', label: 'Event', sortable: true },
  { key: 'status', label: 'Status', sortable: true }, { key: 'http_status', label: 'HTTP' },
  { key: 'duration_ms', label: 'Durasi', align: 'right' }, { key: 'attempts', label: 'Coba', align: 'right' }, { key: 'actions', label: '' },
]
const typeOptions = [{ value: 'ping', label: 'ping (uji kirim)' }, ...props.events.map((e) => ({ value: e.key, label: e.key }))]

const detail = ref(null)
async function open(row) {
  detail.value = (await run(() => webhooksApi.delivery(row.id))).data
}
async function redeliver(row) {
  await run(() => webhooksApi.redeliver(row.id), 'Dikirim ulang — lihat hasilnya di baris teratas.').catch(() => null)
  list.reload()
}
const pretty = (s) => {
  try { return JSON.stringify(JSON.parse(s), null, 2) } catch { return s }
}
defineExpose({ reload: () => list.reload() })
</script>

<template>
  <div class="grid gap-3">
    <form class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center" @submit.prevent="list.search">
      <AppSelect v-model="list.filters.status" placeholder="Semua status" clearable aria-label="Status" class="sm:!w-48"
        :options="Object.entries(STATUS).map(([value, [, label]]) => ({ value, label }))" @update:model-value="list.search" />
      <AppSelect v-model="list.filters.type" placeholder="Semua event" clearable searchable aria-label="Event" class="sm:!w-64"
        :options="typeOptions" @update:model-value="list.search" />
      <AppButton variant="ghost" class="col-span-2 sm:ml-auto" @click="list.reload()"><RefreshCw class="size-4" /> Muat ulang</AppButton>
    </form>
    <DataTable dense :columns="COLUMNS" :rows="list.rows.value" :meta="list.meta.value" :sort="list.sort.value" :loading="list.loading.value"
      empty-text="Belum ada pengiriman. Klik Kirim uji untuk mencoba." @sort="list.toggleSort" @page="list.goto" @per-page="list.setPerPage">
      <template #cell-created_at="{ row: d }"><span class="font-mono text-xs">{{ dateTime(d.created_at) }}</span></template>
      <template #cell-type="{ row: d }"><span class="font-mono text-xs">{{ d.type }}</span></template>
      <template #cell-status="{ row: d }">
        <AppBadge :tone="STATUS[d.status]?.[0]">{{ STATUS[d.status]?.[1] ?? d.status }}</AppBadge>
        <span v-if="d.status === 'retrying' && d.next_retry_at" class="block text-xs text-muted">lagi {{ dateTime(d.next_retry_at) }}</span>
      </template>
      <template #cell-http_status="{ row: d }"><span class="font-mono">{{ d.http_status ?? '—' }}</span></template>
      <template #cell-duration_ms="{ row: d }"><span class="font-mono">{{ d.duration_ms != null ? `${d.duration_ms} ms` : '—' }}</span></template>
      <template #cell-attempts="{ row: d }"><span class="font-mono">{{ d.attempts }}</span></template>
      <template #cell-actions="{ row: d }">
        <span class="flex justify-end gap-1">
          <AppButton variant="ghost" :disabled="busy" @click="open(d)">Detail</AppButton>
          <AppButton v-if="d.type !== 'ping' && endpoint.is_active" variant="ghost" :disabled="busy" :aria-label="`Kirim ulang ${d.type}`" @click="redeliver(d)"><RotateCcw class="size-4" /> Kirim ulang</AppButton>
        </span>
      </template>
    </DataTable>

    <AppModal v-if="detail" size="lg" :title="`${detail.type} · ${STATUS[detail.status]?.[1] ?? detail.status}`" :description="`Delivery ${detail.id} · event ${detail.event_id}`" @close="detail = null">
      <div class="grid gap-4">
        <p v-if="detail.error" class="rounded-md border border-line-strong bg-surface-2 px-3 py-2 text-sm text-danger" role="alert">{{ detail.error }}</p>
        <dl class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-1 text-sm">
          <dt class="text-muted">HTTP</dt><dd class="font-mono">{{ detail.http_status ?? '—' }}</dd>
          <dt class="text-muted">Durasi</dt><dd class="font-mono">{{ detail.duration_ms != null ? `${detail.duration_ms} ms` : '—' }}</dd>
          <dt class="text-muted">Percobaan</dt><dd class="font-mono">{{ detail.attempts }}</dd>
          <dt class="text-muted">Terkirim</dt><dd>{{ detail.delivered_at ? dateTime(detail.delivered_at) : '—' }}</dd>
        </dl>
        <div class="grid gap-1">
          <span class="label">Body permintaan</span>
          <pre class="max-h-80 overflow-auto rounded-md bg-surface-2 p-3 font-mono text-xs">{{ pretty(detail.request_body) }}</pre>
        </div>
        <div class="grid gap-1">
          <span class="label">Balasan penerima (maks 2 KB)</span>
          <pre class="max-h-60 overflow-auto rounded-md bg-surface-2 p-3 font-mono text-xs">{{ detail.response_body || '—' }}</pre>
        </div>
      </div>
      <template #footer><AppButton variant="secondary" @click="detail = null">Tutup</AppButton></template>
    </AppModal>
  </div>
</template>
