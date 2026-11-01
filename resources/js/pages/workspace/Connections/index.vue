<script setup>
// U-06 — daftar koneksi/lead server-side: cari, filter (tahap, sumber, follow-up, event), urut kolom,
// halaman & jumlah per halaman tersimpan di URL. Input manual, ekspor CSV sesuai cakupan izin.
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Blocks, ChartColumn, Download, FileUp, Plus, ScanLine } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppEmpty from '@/components/ui/AppEmpty/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppTextarea from '@/components/ui/AppTextarea/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import StageBadge from '@/components/common/StageBadge/index.vue'
import { eventsApi, leadsApi } from '@/api/workspace'
import { toQuery } from '@/api/client'
import { usePagedList } from '@/composables/usePagedList'
import { dateTime, relative, sourceLabel, SOURCE_LABELS } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'

const router = useRouter()
const auth = useAuthStore()
const toast = useToastStore()
const label = computed(() => auth.contactLabel)

const TAP_SOURCES = ['nfc', 'qr', 'dynamic_qr', 'code', 'link', 'app']
const LEAD_SOURCES = Object.entries(SOURCE_LABELS).filter(([k]) => !TAP_SOURCES.includes(k)).map(([value, l]) => ({ value, label: l }))
const DUE = [
  { value: 'overdue', label: 'Follow-up terlambat' }, { value: 'today', label: 'Follow-up hari ini' },
  { value: 'week', label: 'Follow-up 7 hari' }, { value: 'none', label: 'Belum dijadwalkan' },
]
const COLUMNS = [
  { key: 'name', label: 'Nama', sortable: true },
  { key: 'stage', label: 'Tahap', sortable: true },
  { key: 'source', label: 'Sumber' },
  { key: 'next_followup_at', label: 'Follow-up', sortable: true },
  { key: 'last_activity_at', label: 'Aktivitas terakhir', sortable: true },
  { key: 'created_at', label: 'Ditambahkan', sortable: true },
]

const list = usePagedList(leadsApi.list, { q: '', stage: '', source: '', tag: '', due: '', event: '' }, { sort: '-created_at' })
/** Filter multi-pilih disimpan sebagai "a,b" (sama dengan query URL & API). */
const multi = (key) => computed({ get: () => (list.filters[key] ? list.filters[key].split(',') : []), set: (v) => (list.filters[key] = v.join(',')) })
const stageSel = multi('stage')
const sourceSel = multi('source')
const tagSel = multi('tag')
const tags = ref([])
const { filters } = list
const stages = ref([])
const events = ref([])
const filtered = computed(() => Object.values(filters).some(Boolean))
const exportHref = computed(() => `${leadsApi.exportUrl}?${toQuery(filters)}`)

let t
watch(() => filters.q, () => { clearTimeout(t); t = setTimeout(() => list.search(), 300) })
onMounted(async () => {
  const [s, e, t] = await Promise.all([
    leadsApi.stages(), eventsApi.list({ per_page: 50, sort: '-starts_at' }).catch(() => ({ data: [] })), leadsApi.tags().catch(() => ({ data: [] })),
  ])
  stages.value = s.data
  events.value = e.data
  tags.value = t.data
})

const adding = ref(false)
const form = reactive({ name: '', phone: '', email: '', company: '', job_title: '', note: '' })
const formError = ref('')
async function create() {
  formError.value = ''
  if (!form.name.trim()) return (formError.value = 'Nama wajib diisi.')
  try {
    const res = await leadsApi.create(form)
    if (res.meta?.duplicate) toast.push(`${res.data.name} sudah ada — data digabung ke ${label.value.toLowerCase()} lama.`)
    router.push(`/connections/${res.data.id}`)
  } catch (e) {
    formError.value = e.message
  }
}
const overdue = (iso) => iso && new Date(iso) < new Date()
</script>

<template>
  <PageHeader :eyebrow="list.meta.value ? `${list.meta.value.total} ${label.toLowerCase()}` : label" :title="label">
    <!-- tindakan utama dulu; tautan sekunder jadi ikon + teks ringkas di ponsel agar tidak memenuhi layar -->
    <AppButton v-if="auth.can('leads.edit.*')" @click="adding = true"><Plus class="size-5" /> Tambah</AppButton>
    <AppButton v-if="auth.can('leads.export.*')" variant="secondary" :href="exportHref"><Download class="size-5" /> Ekspor</AppButton>
    <AppButton variant="ghost" to="/connections/dashboard"><ChartColumn class="size-5" /> Dashboard</AppButton>
    <AppButton v-if="auth.can('leads.edit.*')" variant="ghost" to="/scan"><ScanLine class="size-5" /> Scan</AppButton>
    <AppButton v-if="auth.can('leads.edit.*')" variant="ghost" to="/connections/import"><FileUp class="size-5" /> Impor CSV</AppButton>
    <AppButton v-if="auth.can('billing.view')" class="hidden sm:inline-flex" variant="ghost" to="/billing#addons" title="Add-on Integrasi CRM — segera hadir"><Blocks class="size-5" /> Kirim ke CRM <span class="text-xs text-muted">segera</span></AppButton>
  </PageHeader>

  <!-- Ponsel: pencarian selebar layar, filter 2 kolom. Desktop: berderet membungkus. -->
  <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
    <AppInput v-model="filters.q" type="search" placeholder="Cari nama, perusahaan, email, nomor" aria-label="Cari" class="col-span-2 sm:max-w-xs" />
    <AppSelect v-model="stageSel" multiple placeholder="Semua tahap" :options="stages.map((s) => ({ value: s.id, label: s.name }))" aria-label="Tahap" class="sm:!w-auto sm:min-w-44" />
    <AppSelect v-model="sourceSel" multiple placeholder="Semua sumber" :options="LEAD_SOURCES" aria-label="Sumber" class="sm:!w-auto sm:min-w-44" />
    <AppSelect v-if="tags.length" v-model="tagSel" multiple searchable placeholder="Semua tag" :options="tags.map((t) => ({ value: t.value, label: t.value, description: `${t.count} ${label.toLowerCase()}` }))" aria-label="Tag" class="sm:!w-auto sm:min-w-44" />
    <AppSelect v-model="filters.due" clearable placeholder="Semua follow-up" :options="DUE" aria-label="Follow-up" class="sm:!w-auto sm:min-w-44" />
    <AppSelect v-if="events.length" v-model="filters.event" clearable placeholder="Semua event" :options="events.map((e) => ({ value: e.id, label: e.name, description: e.location }))" aria-label="Event" class="sm:!w-auto sm:min-w-44" />
  </div>

  <AppEmpty
    v-if="list.rows.value && !list.rows.value.length && !filtered"
    :text="`${label} muncul saat seseorang menekan Tukar Kontak di profil Anda, saat Anda men-scan kartu orang lain, mengimpor CSV, atau menambahkannya manual.`"
  />
  <DataTable
    v-else
    :columns="COLUMNS" :rows="list.rows.value" :meta="list.meta.value" :sort="list.sort.value" :loading="list.loading.value"
    empty-text="Tidak ada yang cocok dengan filter." @sort="list.toggleSort" @page="list.goto" @per-page="list.setPerPage"
  >
    <template #cell-name="{ row: c }">
      <RouterLink :to="`/connections/${c.id}`" class="font-semibold">{{ c.name }}</RouterLink>
      <span class="block text-sm text-muted">{{ [c.job_title, c.company].filter(Boolean).join(' · ') || c.email || c.phone }}</span>
    </template>
    <template #cell-stage="{ row: c }"><StageBadge :stage="c.stage" /></template>
    <template #cell-source="{ row: c }">{{ sourceLabel(c.source) }}<span v-if="c.event" class="block text-xs text-muted">{{ c.event.name }}</span></template>
    <template #cell-next_followup_at="{ row: c }">
      <span v-if="c.next_followup_at" :class="overdue(c.next_followup_at) ? 'font-medium text-danger' : ''" :title="dateTime(c.next_followup_at)">
        {{ overdue(c.next_followup_at) ? 'Terlambat · ' : '' }}{{ relative(c.next_followup_at) }}
      </span>
      <span v-else class="text-muted">—</span>
    </template>
    <template #cell-last_activity_at="{ row: c }">{{ relative(c.last_activity_at || c.created_at) }}</template>
    <template #cell-created_at="{ row: c }"><span class="font-mono text-sm">{{ dateTime(c.created_at) }}</span></template>
  </DataTable>

  <AppModal v-if="adding" :title="`Tambah ${label.toLowerCase()}`" @close="adding = false">
    <form id="lead-create" class="grid gap-4" @submit.prevent="create">
      <AppField label="Nama *"><AppInput v-model="form.name" maxlength="120" required /></AppField>
      <div class="grid gap-4 sm:grid-cols-2">
        <AppField label="WhatsApp / telepon"><AppInput v-model="form.phone" inputmode="tel" /></AppField>
        <AppField label="Email"><AppInput v-model="form.email" type="email" /></AppField>
        <AppField label="Perusahaan"><AppInput v-model="form.company" /></AppField>
        <AppField label="Jabatan"><AppInput v-model="form.job_title" /></AppField>
      </div>
      <AppField label="Catatan"><AppTextarea v-model="form.note" /></AppField>
      <p v-if="formError" class="text-sm text-danger" role="alert">{{ formError }}</p>
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="adding = false">Batal</AppButton>
      <AppButton type="submit" form="lead-create"><Plus class="size-5" /> Simpan</AppButton>
    </template>
  </AppModal>
</template>
