<script setup>
import { download } from '@/api/client'
// Daftar hadir event: jumlah, siapa saja (terbaru di atas, diperbarui tiap 15 detik), ekspor CSV, batalkan check-in yang salah.
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { Download, ScanLine, Undo2 } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import { checkinsApi } from '@/api/workspace'
import { useAction } from '@/composables/useAsync'
import { usePagedList } from '@/composables/usePagedList'
import { dateTime } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'

const props = defineProps({ eventId: { type: String, required: true } })
const auth = useAuthStore()
const list = usePagedList((p) => checkinsApi.list(props.eventId, p), {}, { url: false, sort: '-at', perPage: 10 })
const COLUMNS = [{ key: 'name', label: 'Peserta' }, { key: 'method', label: 'Cara' }, { key: 'by', label: 'Dicatat oleh' }, { key: 'at', label: 'Waktu', sortable: true }, { key: 'actions' }]
const METHOD = { nfc: 'Tap NFC', qr: 'Scan QR', code: 'Kode kartu' }
const canUndo = auth.can('events.manage.all')
const { busy, run } = useAction()
const undoing = ref(null)
async function undo() {
  await run(() => checkinsApi.remove(props.eventId, undoing.value.id), 'Check-in dibatalkan.').catch(() => null)
  undoing.value = null
  list.reload()
}
let timer
onMounted(() => { timer = setInterval(() => list.reload(), 15_000) })
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <AppPanel :title="`Daftar hadir · ${list.meta.value?.total ?? 0}`" flush>
    <template #actions>
      <AppButton v-if="auth.can('events.checkin')" variant="ghost" :to="`/events/${eventId}/checkin`"><ScanLine class="size-4" /> Check-in peserta</AppButton>
      <AppButton variant="ghost" @click="download(checkinsApi.exportUrl(eventId), 'checkin.csv')"><Download class="size-4" /> CSV</AppButton>
    </template>
    <DataTable
      dense :columns="COLUMNS" :rows="list.rows.value" :meta="list.meta.value" :sort="list.sort.value" :loading="list.loading.value"
      empty-text="Belum ada peserta yang check-in." @sort="list.toggleSort" @page="list.goto" @per-page="list.setPerPage"
    >
      <template #cell-name="{ row: c }">{{ c.name }}<span v-if="c.guest" class="block text-xs text-muted">Tamu dari workspace lain</span></template>
      <template #cell-method="{ row: c }">{{ METHOD[c.method] ?? c.method }}</template>
      <template #cell-by="{ row: c }">{{ c.by?.name ?? '—' }}</template>
      <template #cell-at="{ row: c }"><span class="font-mono text-sm">{{ dateTime(c.at) }}</span></template>
      <template #cell-actions="{ row: c }">
        <AppButton v-if="canUndo" variant="ghost" :aria-label="`Batalkan check-in ${c.name}`" @click="undoing = c"><Undo2 class="size-4" /></AppButton>
      </template>
    </DataTable>
  </AppPanel>

  <AppModal v-if="undoing" size="sm" title="Batalkan check-in?" :description="`${undoing.name} akan dihapus dari daftar hadir. Peserta bisa check-in lagi.`" @close="undoing = null">
    <template #footer>
      <AppButton variant="secondary" @click="undoing = null">Tutup</AppButton>
      <AppButton variant="danger" :loading="busy" @click="undo">Batalkan check-in</AppButton>
    </template>
  </AppModal>
</template>
