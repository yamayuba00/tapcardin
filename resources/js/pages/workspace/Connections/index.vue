<script setup>
// U-06 — daftar koneksi/lead server-side: cari, filter (tahap, sumber, follow-up, event), urut kolom,
// halaman & jumlah per halaman tersimpan di URL. Input manual, ekspor CSV sesuai cakupan izin.
// Filter per kartu; daftar dimuat ulang otomatis tiap 1 menit (koneksi dari Tukar Kontak langsung muncul).
// Paket dengan batas koneksi: sisanya tetap tersimpan tapi disembunyikan sampai upgrade.
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Blocks, ChartColumn, Download, FileUp, Lock, Plus, ScanLine, SlidersHorizontal } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppEmpty from '@/components/ui/AppEmpty/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppMenu from '@/components/ui/AppMenu/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppTextarea from '@/components/ui/AppTextarea/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import RefreshStatus from '@/components/common/RefreshStatus/index.vue'
import StageBadge from '@/components/common/StageBadge/index.vue'
import { cardsApi, eventsApi, leadsApi } from '@/api/workspace'
import { toQuery } from '@/api/client'
import { useAutoRefresh } from '@/composables/useAutoRefresh'
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

const list = usePagedList(leadsApi.list, { q: '', stage: '', source: '', tag: '', due: '', event: '', card: '' }, { sort: '-created_at' })
/** Filter multi-pilih disimpan sebagai "a,b" (sama dengan query URL & API). */
const multi = (key) => computed({ get: () => (list.filters[key] ? list.filters[key].split(',') : []), set: (v) => (list.filters[key] = v.join(',')) })
const stageSel = multi('stage')
const sourceSel = multi('source')
const tagSel = multi('tag')
const tags = ref([])
const { filters } = list
const stages = ref([])
const events = ref([])
const cards = ref([])
const auto = useAutoRefresh(() => list.reload(), 60_000, { on: ['lead_new'] })
watch(list.rows, auto.touch)
const hidden = computed(() => list.meta.value?.hidden ?? 0)
const menu = computed(() => [
  auth.can('leads.edit.*') && { label: 'Impor CSV', icon: FileUp, onSelect: () => router.push('/connections/import') },
  auth.can('leads.export.*') && { label: 'Ekspor CSV (sesuai filter)', icon: Download, onSelect: () => (window.location.href = exportHref.value) },
  auth.can('billing.view') && { label: 'Kirim ke CRM — segera hadir', icon: Blocks, onSelect: () => router.push('/addons') },
].filter(Boolean))
const filtered = computed(() => Object.values(filters).some(Boolean))
// ponsel: filter dilipat di balik tombol "Filter" (angka = filter aktif selain pencarian)
const showFilters = ref(false)
const activeFilters = computed(() => Object.entries(filters).filter(([k, v]) => k !== 'q' && v).length)
const exportHref = computed(() => `${leadsApi.exportUrl}?${toQuery(filters)}`)

let t
watch(() => filters.q, () => { clearTimeout(t); t = setTimeout(() => list.search(), 300) })
onMounted(async () => {
  const [s, e, t, c] = await Promise.all([
    leadsApi.stages(), eventsApi.list({ per_page: 50, sort: '-starts_at' }).catch(() => ({ data: [] })), leadsApi.tags().catch(() => ({ data: [] })),
    cardsApi.list().catch(() => ({ data: [] })),
  ])
  cards.value = c.data
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
    <!-- satu tindakan utama, dua sekunder bergaya sama, sisanya di menu "Lainnya" -->
    <div class="flex flex-wrap items-center gap-2">
      <AppButton v-if="auth.can('leads.edit.*')" @click="adding = true"><Plus class="size-5" /> Tambah</AppButton>
      <!-- ponsel: tombol sekunder cukup ikon agar semua muat satu baris -->
      <AppButton v-if="auth.can('leads.edit.*')" variant="secondary" to="/scan" aria-label="Scan kartu" class="max-sm:w-11 max-sm:!px-0"><ScanLine class="size-5" /> <span class="hidden sm:inline">Scan kartu</span></AppButton>
      <AppButton variant="secondary" to="/connections/dashboard" aria-label="Dashboard" class="max-sm:w-11 max-sm:!px-0"><ChartColumn class="size-5" /> <span class="hidden sm:inline">Dashboard</span></AppButton>
      <AppMenu v-if="menu.length" :items="menu" label="Tindakan lain" />
    </div>
  </PageHeader>

  <div v-if="hidden" class="flex flex-wrap items-center gap-3 rounded-md border border-line-strong bg-surface-2 px-4 py-3 text-sm" role="status">
    <Lock class="size-5 flex-none text-warn" aria-hidden="true" />
    <span class="min-w-0 flex-1">
      {{ hidden }} {{ label.toLowerCase() }} terbaru tersimpan aman tapi disembunyikan — paket Anda menampilkan {{ list.meta.value.plan_limit }} {{ label.toLowerCase() }} pertama.
      Tidak ada yang dihapus; semuanya langsung terlihat setelah upgrade.
    </span>
    <AppButton v-if="auth.can('billing.view')" variant="secondary" to="/billing">Upgrade ke Pro</AppButton>
  </div>

  <!-- Ponsel: pencarian + tombol Filter; pilihan filter 2 kolom saat dibuka. Desktop: semuanya berderet membungkus. -->
  <div class="grid gap-2 sm:flex sm:flex-wrap">
    <div class="flex gap-2 sm:contents">
      <AppInput v-model="filters.q" type="search" placeholder="Cari nama, perusahaan, email, nomor" aria-label="Cari" class="min-w-0 flex-1 sm:max-w-xs" />
      <AppButton variant="secondary" class="flex-none sm:!hidden" :aria-expanded="showFilters" aria-controls="lead-filters" @click="showFilters = !showFilters">
        <SlidersHorizontal class="size-5" /> Filter<span v-if="activeFilters" class="grid size-5 place-items-center rounded-full bg-accent text-xs text-accent-ink">{{ activeFilters }}</span>
      </AppButton>
    </div>
    <div id="lead-filters" :class="['grid-cols-2 gap-2 sm:contents', showFilters ? 'grid' : 'hidden']">
      <AppSelect v-model="stageSel" multiple placeholder="Semua tahap" :options="stages.map((s) => ({ value: s.id, label: s.name }))" aria-label="Tahap" class="sm:!w-auto sm:min-w-44" />
      <AppSelect v-model="sourceSel" multiple placeholder="Semua sumber" :options="LEAD_SOURCES" aria-label="Sumber" class="sm:!w-auto sm:min-w-44" />
      <AppSelect v-if="tags.length" v-model="tagSel" multiple searchable placeholder="Semua tag" :options="tags.map((t) => ({ value: t.value, label: t.value, description: `${t.count} ${label.toLowerCase()}` }))" aria-label="Tag" class="sm:!w-auto sm:min-w-44" />
      <AppSelect v-model="filters.due" clearable placeholder="Semua follow-up" :options="DUE" aria-label="Follow-up" class="sm:!w-auto sm:min-w-44" />
      <AppSelect v-if="events.length" v-model="filters.event" clearable placeholder="Semua event" :options="events.map((e) => ({ value: e.id, label: e.name, description: e.location }))" aria-label="Event" class="sm:!w-auto sm:min-w-44" />
      <AppSelect
        v-if="cards.length > 1" v-model="filters.card" clearable placeholder="Semua kartu" aria-label="Kartu" class="sm:!w-auto sm:min-w-44"
        :options="cards.map((c) => ({ value: c.token, label: c.label || `Kartu ${c.short_code}`, description: c.form === 'digital' ? 'Digital' : c.short_code }))"
      />
    </div>
  </div>
  <RefreshStatus :last-at="auto.lastAt.value" :refreshing="auto.refreshing.value" :every="auto.every" :live="auto.live.value" class="sm:justify-end" @refresh="auto.refresh" />

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
    <template #cell-source="{ row: c }">
      {{ sourceLabel(c.source) }}
      <RouterLink v-if="c.card" :to="`/cards/${c.card.token}`" class="block text-xs text-muted">via {{ c.card.label }}</RouterLink>
      <span v-if="c.event" class="block text-xs text-muted">{{ c.event.name }}</span>
    </template>
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
