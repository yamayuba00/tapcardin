<script setup>
// LD-10 — impor CSV: 1) unggah  2) cocokkan kolom + pratinjau 20 baris  3) diproses di antrean → ringkasan.
// Duplikat (nomor/email sama) digabung ke koneksi lama, sama seperti input manual.
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { CircleAlert, CircleCheck, FileUp, LoaderCircle } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppTable from '@/components/ui/AppTable/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { importsApi } from '@/api/workspace'
import { useAction } from '@/composables/useAsync'
import { usePagedList } from '@/composables/usePagedList'
import { dateTime, num } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const { busy, run } = useAction()
const FIELDS = { name: 'Nama *', phone: 'WhatsApp / telepon', email: 'Email', company: 'Perusahaan', job_title: 'Jabatan', note: 'Catatan', tags: 'Tag (pisahkan dengan koma)' }

const step = ref('upload') // upload | map | run
const current = ref(null) // impor aktif
const sample = ref([])
const mapping = reactive({})
const dragging = ref(false)
const history = usePagedList(importsApi.list, {}, { url: false, sort: '-created_at', perPage: 10 })

async function upload(file) {
  if (!file) return
  const res = await run(() => importsApi.upload(file)).catch(() => null)
  if (!res) return
  current.value = res.data
  sample.value = res.data.sample
  Object.keys(FIELDS).forEach((f) => (mapping[f] = res.data.mapping[f] ?? ''))
  step.value = 'map'
}
const onPick = (e) => { upload(e.target.files?.[0]); e.target.value = '' }
const onDrop = (e) => { dragging.value = false; upload(e.dataTransfer.files?.[0]) }

const columnOptions = computed(() => (current.value?.headers ?? []).map((h, i) => ({ value: i, label: h || `Kolom ${i + 1}` })))
const used = computed(() => Object.values(mapping).filter((v) => v !== '' && v !== null).map(Number))
const duplicate = computed(() => used.value.length !== new Set(used.value).size)
const mappedCol = (i) => Object.entries(mapping).find(([, v]) => v !== '' && Number(v) === i)?.[0]

let poll = null
async function start() {
  const clean = Object.fromEntries(Object.entries(mapping).filter(([, v]) => v !== '' && v !== null).map(([k, v]) => [k, Number(v)]))
  const res = await run(() => importsApi.start(current.value.id, clean)).catch(() => null)
  if (!res) return
  current.value = res.data
  step.value = 'run'
  watch()
}
function watch() {
  clearInterval(poll)
  poll = setInterval(async () => {
    const res = await importsApi.get(current.value.id).catch(() => null)
    if (!res) return
    current.value = res.data
    if (['done', 'failed'].includes(res.data.status)) {
      clearInterval(poll)
      history.reload()
    }
  }, 1500)
}
onBeforeUnmount(() => clearInterval(poll))
function again() {
  step.value = 'upload'
  current.value = null
}
const processed = computed(() => (current.value ? current.value.created + current.value.merged + current.value.failed : 0))
const STATUS = { uploaded: 'Belum diproses', queued: 'Antre', processing: 'Diproses', done: 'Selesai', failed: 'Gagal' }
</script>

<template>
  <PageHeader :eyebrow="auth.contactLabel" title="Impor CSV" back="/connections" :back-label="auth.contactLabel" />

  <AppPanel v-if="step === 'upload'" title="1 · Unggah file">
    <label
      :class="['grid cursor-pointer justify-items-center gap-3 rounded-md border-2 border-dashed p-10 text-center', dragging ? 'border-accent bg-surface-2' : 'border-line-strong']"
      @dragover.prevent="dragging = true" @dragleave="dragging = false" @drop.prevent="onDrop"
    >
      <component :is="busy ? LoaderCircle : FileUp" :class="['size-10 text-accent-text', busy ? 'animate-spin' : '']" aria-hidden="true" />
      <span class="font-semibold">{{ busy ? 'Membaca file…' : 'Pilih atau tarik file .csv ke sini' }}</span>
      <span class="max-w-md text-sm text-muted">Dari Excel: File → Save As → CSV. Dari Google Sheets: File → Download → CSV. Baris pertama harus berisi judul kolom. Maks 10.000 baris, 5 MB.</span>
      <input class="sr-only" type="file" accept=".csv,text/csv,text/plain" :disabled="busy" @change="onPick" />
    </label>
  </AppPanel>

  <AppPanel v-else-if="step === 'map'" :title="`2 · Cocokkan kolom — ${current.filename} (${num(current.total_rows)} baris)`">
    <div class="grid gap-6">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <label v-for="(lbl, f) in FIELDS" :key="f" class="grid gap-1.5">
          <span class="text-sm font-medium">{{ lbl }}</span>
          <AppSelect v-model="mapping[f]" :placeholder="f === 'name' ? 'Pilih kolom' : 'Tidak diimpor'" :options="columnOptions" :aria-label="lbl" />
        </label>
      </div>
      <p v-if="duplicate" class="flex items-center gap-2 text-sm text-danger" role="alert"><CircleAlert class="size-4" /> Satu kolom hanya boleh dipakai untuk satu isian.</p>

      <div class="grid gap-2">
        <span class="label">Pratinjau {{ sample.length }} baris pertama</span>
        <AppTable dense>
          <thead><tr><th v-for="(h, i) in current.headers" :key="i" :class="mappedCol(i) ? '!text-accent-text' : ''">{{ h }}<span v-if="mappedCol(i)" class="block normal-case">→ {{ FIELDS[mappedCol(i)].replace(' *', '') }}</span></th></tr></thead>
          <tbody><tr v-for="(r, ri) in sample" :key="ri"><td v-for="(_, i) in current.headers" :key="i" :class="mappedCol(i) ? '' : 'text-muted'">{{ r[i] }}</td></tr></tbody>
        </AppTable>
      </div>

      <div class="flex flex-wrap gap-2">
        <AppButton :loading="busy" :disabled="mapping.name === '' || duplicate" @click="start">Impor {{ num(current.total_rows) }} baris</AppButton>
        <AppButton variant="secondary" @click="again">Ganti file</AppButton>
      </div>
      <p class="text-sm text-muted">Nomor atau email yang sudah ada akan digabung ke {{ auth.contactLabel.toLowerCase() }} lama, bukan dibuat dobel.</p>
    </div>
  </AppPanel>

  <AppPanel v-else :title="`3 · ${STATUS[current.status]}`">
    <div class="grid gap-4">
      <div v-if="!['done', 'failed'].includes(current.status)" class="flex items-center gap-3">
        <LoaderCircle class="size-6 animate-spin text-accent-text" aria-hidden="true" />
        <span aria-live="polite">Memproses {{ num(processed) }} dari {{ num(current.total_rows) }} baris… Anda boleh meninggalkan halaman ini; notifikasi muncul saat selesai.</span>
      </div>
      <div v-else class="flex items-center gap-3">
        <component :is="current.status === 'done' ? CircleCheck : CircleAlert" :class="['size-6', current.status === 'done' ? 'text-ok' : 'text-danger']" aria-hidden="true" />
        <span>{{ current.status === 'done' ? 'Impor selesai.' : 'Impor berhenti karena kesalahan.' }}</span>
      </div>
      <dl class="grid grid-cols-3 gap-4 text-center">
        <div class="rounded-md bg-surface-2 p-4"><dt class="label">Baru</dt><dd class="num text-[28px]">{{ num(current.created) }}</dd></div>
        <div class="rounded-md bg-surface-2 p-4"><dt class="label">Digabung</dt><dd class="num text-[28px]">{{ num(current.merged) }}</dd></div>
        <div class="rounded-md bg-surface-2 p-4"><dt class="label">Gagal</dt><dd :class="['num text-[28px]', current.failed ? 'text-danger' : '']">{{ num(current.failed) }}</dd></div>
      </dl>
      <div v-if="current.errors.length" class="grid gap-1">
        <span class="label">Baris yang dilewati{{ current.failed > current.errors.length ? ` (${current.errors.length} pertama)` : '' }}</span>
        <ul class="max-h-60 overflow-y-auto rounded-md border border-line text-sm">
          <li v-for="er in current.errors" :key="er.row" class="flex gap-3 border-b border-line px-3 py-2 last:border-b-0"><span class="font-mono text-muted">baris {{ er.row }}</span>{{ er.reason }}</li>
        </ul>
      </div>
      <div v-if="['done', 'failed'].includes(current.status)" class="flex gap-2">
        <AppButton to="/connections?source=import">Lihat hasil impor</AppButton>
        <AppButton variant="secondary" @click="again">Impor file lain</AppButton>
      </div>
    </div>
  </AppPanel>

  <h2 class="text-base font-semibold">Riwayat impor</h2>
  <DataTable
    :columns="[{ key: 'filename', label: 'File', sortable: true }, { key: 'status', label: 'Status' }, { key: 'result', label: 'Hasil' }, { key: 'created_at', label: 'Waktu', sortable: true }]"
    :rows="history.rows.value" :meta="history.meta.value" :sort="history.sort.value" :loading="history.loading.value"
    empty-text="Belum pernah mengimpor." @sort="history.toggleSort" @page="history.goto" @per-page="history.setPerPage"
  >
    <template #cell-status="{ row }">{{ STATUS[row.status] }}</template>
    <template #cell-result="{ row }"><span class="text-sm">{{ num(row.created) }} baru · {{ num(row.merged) }} digabung · {{ num(row.failed) }} gagal</span></template>
    <template #cell-created_at="{ row }"><span class="font-mono text-sm">{{ dateTime(row.created_at) }}</span></template>
  </DataTable>
</template>
