<script setup>
// Pemantauan uptime (ala Uptime Kuma): monitor HTTP/SSL, status & grafik realtime (Reverb), serta info gangguan /
// maintenance yang tampil di halaman status publik. Lihat: dashboard.ops · ubah: system.manage.
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { Bell, ExternalLink, Pause, Play, Plus, RefreshCw, Trash2 } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppEmpty from '@/components/ui/AppEmpty/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import AppTextarea from '@/components/ui/AppTextarea/index.vue'
import AppToggle from '@/components/ui/AppToggle/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import ResponseChart from '@/components/uptime/ResponseChart/index.vue'
import UptimeBars from '@/components/uptime/UptimeBars/index.vue'
import { adminApi } from '@/api/admin'
import { useAction, useAsync } from '@/composables/useAsync'
import { dateTime, num, relative } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import { useUptimeStore } from '@/stores/uptime'

const auth = useAuthStore()
const uptime = useUptimeStore()
const canManage = computed(() => auth.can('system.manage'))
const { data, reload } = useAsync(() => adminApi.uptime())
const { busy, run } = useAction()

const STATUS = {
  up: { tone: 'ok', label: 'Normal', bar: 'ok' }, down: { tone: 'danger', label: 'Down', bar: 'danger' },
  degraded: { tone: 'warn', label: 'Perhatian', bar: 'warn' }, pending: { tone: 'muted', label: 'Menunggu', bar: 'none' },
  paused: { tone: 'muted', label: 'Dijeda', bar: 'none' },
}
const MODE = { realtime: ['ok', 'Realtime'], connecting: ['muted', 'Menyambung…'], offline: ['warn', 'Realtime terputus'], off: ['muted', 'Realtime mati'] }
const pct = (v) => (v == null ? '—' : `${Number(v).toLocaleString('id-ID', { maximumFractionDigits: 2 })}%`)
const counts = computed(() => {
  const c = { up: 0, down: 0, degraded: 0, paused: 0 }
  for (const m of data.value?.monitors ?? []) if (m.status in c) c[m.status]++
  return c
})
// 40 slot tetap (kosong di kiri sampai riwayat terisi) agar lebar bar tidak berubah-ubah
const recentBars = (m) => [
  ...Array.from({ length: Math.max(0, 40 - m.recent.length) }, (_, i) => ({ key: `e${i}`, tone: 'none', label: 'Belum ada data' })),
  ...m.recent.map((r, i) => ({ key: String(i), tone: STATUS[r.s]?.bar ?? 'none', label: r.s === 'down' ? 'Gagal' : `${STATUS[r.s]?.label ?? r.s} · ${r.ms ?? '—'} ms` })),
]

// waktu relatif ikut berjalan
const now = ref(new Date())
const tick = setInterval(() => (now.value = new Date()), 15000)

// ── realtime: perbarui baris & detail tanpa memuat ulang ──
const detail = ref(null)
const off = uptime.onCheck(({ monitor, check }) => {
  const list = data.value?.monitors
  const i = list?.findIndex((m) => m.id === monitor.id) ?? -1
  if (i >= 0) {
    const recent = [...list[i].recent, { s: check.status, ms: check.response_ms }].slice(-40)
    list.splice(i, 1, { ...list[i], ...monitor, recent })
  }
  if (detail.value?.monitor.id === monitor.id) {
    detail.value.monitor = { ...detail.value.monitor, ...monitor }
    detail.value.checks.push({ status: check.status, ms: check.response_ms, code: check.http_status, error: check.error, at: check.at })
  }
})
onBeforeUnmount(() => { off(); clearInterval(tick) })

async function openDetail(m) {
  detail.value = { monitor: m, checks: [], events: [], loading: true }
  detail.value = { ...(await adminApi.monitor(m.id)).data, loading: false }
}
async function checkNow(m) {
  const res = await run(() => adminApi.checkMonitor(m.id), 'Dicek.').catch(() => null)
  if (res && detail.value?.monitor.id === m.id) detail.value = { ...res.data, loading: false }
}

// ── formulir monitor ──
const editing = ref(null)
const form = reactive({})
const errors = ref({})
const INTERVALS = [30, 60, 120, 300, 600, 1800, 3600].map((s) => ({ value: s, label: s < 60 ? `${s} detik` : s < 3600 ? `${s / 60} menit` : '1 jam' }))
function openForm(m = null) {
  errors.value = {}
  Object.assign(form, m
    ? { ...m, keyword: m.keyword ?? '', public_name: m.public_name ?? '' }
    : { name: '', type: 'http', url: 'https://', method: 'GET', expected_status: '200-399', keyword: '', interval_seconds: 60, timeout_seconds: 10, retries: 1, ssl_warn_days: 14, is_public: false, public_name: '', is_active: true })
  editing.value = m ?? 'new'
}
async function saveMonitor() {
  errors.value = {}
  const body = {
    name: form.name, type: form.type, url: form.url, method: form.method, expected_status: form.expected_status, keyword: form.keyword || null,
    interval_seconds: Number(form.interval_seconds), timeout_seconds: Number(form.timeout_seconds), retries: Number(form.retries),
    ssl_warn_days: Number(form.ssl_warn_days), is_public: form.is_public, public_name: form.public_name || null, is_active: form.is_active,
  }
  try {
    await run(() => (editing.value === 'new' ? adminApi.createMonitor(body) : adminApi.updateMonitor(editing.value.id, body)), 'Monitor disimpan.')
    editing.value = null
    reload()
  } catch (e) { errors.value = e.fields ?? {} }
}
async function togglePause(m) {
  await run(() => adminApi.updateMonitor(m.id, { is_active: !m.is_active }), m.is_active ? 'Monitor dijeda.' : 'Monitor aktif lagi.').catch(() => null)
  reload()
}
const removing = ref(null)
async function removeMonitor() {
  await run(() => adminApi.deleteMonitor(removing.value.id), 'Monitor dihapus.').catch(() => null)
  if (detail.value?.monitor.id === removing.value.id) detail.value = null
  removing.value = null
  reload()
}

// ── info gangguan / maintenance ──
const KIND = [{ value: 'incident', label: 'Gangguan' }, { value: 'maintenance', label: 'Maintenance' }]
const IMPACT = [{ value: 'minor', label: 'Sebagian terganggu' }, { value: 'major', label: 'Gangguan besar' }]
const statusOptions = (kind) => Object.entries(data.value?.statuses?.[kind] ?? {}).map(([value, label]) => ({ value, label }))
const incidentForm = reactive({})
const incidentErrors = ref({})
const writing = ref(null) // 'new' | incident (pembaruan)
function openIncident(i = null) {
  incidentErrors.value = {}
  Object.assign(incidentForm, i
    ? { kind: i.kind, status: i.status, body: '' }
    : { kind: 'incident', title: '', impact: 'minor', status: 'investigating', body: '', starts_at: '', ends_at: '' })
  writing.value = i ?? 'new'
}
function setKind(kind) {
  incidentForm.kind = kind
  incidentForm.status = kind === 'incident' ? 'investigating' : 'scheduled'
}
async function saveIncident() {
  incidentErrors.value = {}
  const wib = (v) => (v ? `${v}:00+07:00` : null)
  try {
    if (writing.value === 'new') {
      await run(() => adminApi.createIncident({ ...incidentForm, starts_at: wib(incidentForm.starts_at), ends_at: wib(incidentForm.ends_at) }), 'Info dipublikasikan di halaman status.')
    } else {
      await run(() => adminApi.updateIncident(writing.value.id, { status: incidentForm.status, body: incidentForm.body }), 'Pembaruan dipublikasikan.')
    }
    writing.value = null
    reload()
  } catch (e) { incidentErrors.value = e.fields ?? {} }
}
async function removeIncident(i) {
  await run(() => adminApi.deleteIncident(i.id), 'Info dihapus.').catch(() => null)
  reload()
}
</script>

<template>
  <PageHeader eyebrow="Sistem" title="Uptime">
    <template #meta><p class="text-muted">Pemantauan layanan & info gangguan untuk halaman status publik.</p></template>
    <AppBadge :tone="MODE[uptime.mode][0]" dot>{{ MODE[uptime.mode][1] }}</AppBadge>
    <AppButton v-if="uptime.permission === 'default'" variant="ghost" @click="uptime.askPermission()"><Bell class="size-5" /> Notifikasi browser</AppButton>
    <AppButton v-if="data" variant="secondary" :href="data.status_url" target="_blank" rel="noopener"><ExternalLink class="size-5" /> Halaman status</AppButton>
    <AppButton v-if="canManage" @click="openForm()"><Plus class="size-5" /> Monitor baru</AppButton>
  </PageHeader>

  <AppSkeleton v-if="!data" class="!h-80" />
  <template v-else>
    <ul class="grid grid-cols-2 gap-3 sm:grid-cols-4" aria-label="Ringkasan">
      <li v-for="(n, k) in counts" :key="k" class="grid gap-1 rounded-md border border-line bg-surface p-4">
        <span class="label">{{ STATUS[k].label }}</span>
        <span class="text-2xl font-semibold" :class="{ 'text-danger': k === 'down' && n, 'text-warn': k === 'degraded' && n }">{{ n }}</span>
      </li>
    </ul>

    <AppPanel title="Monitor" flush>
      <AppEmpty v-if="!data.monitors.length" text="Belum ada monitor." />
      <ul v-else>
        <li v-for="m in data.monitors" :key="m.id" class="grid gap-3 border-b border-line px-4 py-4 last:border-b-0 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_auto] lg:items-center">
          <button type="button" class="grid min-w-0 cursor-pointer gap-1 text-left" @click="openDetail(m)">
            <span class="flex flex-wrap items-center gap-2">
              <AppBadge :tone="STATUS[m.status].tone" dot>{{ STATUS[m.status].label }}</AppBadge>
              <span class="font-semibold">{{ m.name }}</span>
              <AppBadge v-if="m.is_public" tone="outline">Publik</AppBadge>
              <AppBadge v-if="m.type === 'ssl'" tone="outline">SSL</AppBadge>
            </span>
            <span class="truncate font-mono text-xs text-muted">{{ m.url }}</span>
            <span v-if="m.last_error && m.status !== 'up'" class="text-sm text-danger">{{ m.last_error }}</span>
          </button>
          <div class="grid gap-2">
            <UptimeBars :items="recentBars(m)" :label="`Cek terakhir ${m.name}`" height="h-6" />
            <p class="flex flex-wrap gap-x-4 text-xs text-muted">
              <span>24 jam <strong class="text-fg">{{ pct(m.uptime_24h) }}</strong></span>
              <span>30 hari <strong class="text-fg">{{ pct(m.uptime_30d) }}</strong></span>
              <span v-if="m.type === 'http'">rata-rata <strong class="text-fg">{{ num(m.avg_ms_24h) }} ms</strong></span>
              <span v-else-if="m.ssl_expires_at">berlaku s.d. <strong class="text-fg">{{ dateTime(m.ssl_expires_at) }}</strong></span>
              <span>{{ m.last_checked_at ? relative(m.last_checked_at, now) : 'belum dicek' }}</span>
            </p>
          </div>
          <div class="flex flex-wrap gap-1">
            <AppButton variant="ghost" :disabled="busy || !m.is_active" aria-label="Cek sekarang" @click="checkNow(m)"><RefreshCw class="size-5" /></AppButton>
            <template v-if="canManage">
              <AppButton variant="ghost" :aria-label="m.is_active ? 'Jeda' : 'Lanjutkan'" @click="togglePause(m)"><component :is="m.is_active ? Pause : Play" class="size-5" /></AppButton>
              <AppButton variant="secondary" @click="openForm(m)">Ubah</AppButton>
            </template>
          </div>
        </li>
      </ul>
    </AppPanel>

    <AppPanel title="Info gangguan & maintenance">
      <template v-if="canManage" #actions><AppButton variant="secondary" @click="openIncident()"><Plus class="size-5" /> Info baru</AppButton></template>
      <AppEmpty v-if="!data.incidents.length" text="Tidak ada info aktif. Info yang dibuat di sini langsung tampil di halaman status." />
      <ul v-else class="grid gap-3">
        <li v-for="i in data.incidents" :key="i.id" class="grid gap-2 rounded-md border border-line p-4" :class="{ 'opacity-70': i.resolved_at }">
          <div class="flex flex-wrap items-center gap-2">
            <AppBadge :tone="i.resolved_at ? 'ok' : i.kind === 'maintenance' ? 'info' : i.impact === 'major' ? 'danger' : 'warn'">{{ i.status_label }}</AppBadge>
            <span class="font-semibold">{{ i.title }}</span>
            <span class="text-sm text-muted">{{ i.kind === 'maintenance' ? 'Maintenance' : 'Gangguan' }} · {{ dateTime(i.starts_at) }}</span>
          </div>
          <p class="text-sm whitespace-pre-line">{{ i.updates[0]?.body }}</p>
          <div v-if="canManage" class="flex flex-wrap gap-2">
            <AppButton v-if="!i.resolved_at" variant="secondary" @click="openIncident(i)">Tambah pembaruan</AppButton>
            <AppButton variant="ghost" aria-label="Hapus info" @click="removeIncident(i)"><Trash2 class="size-5 text-danger" /> Hapus</AppButton>
          </div>
        </li>
      </ul>
    </AppPanel>

    <p class="text-sm text-muted">
      Pemeriksaan berjalan dari server ini. Bila server-nya sendiri mati, tidak ada yang mengirim kabar — tambahkan pemantau
      eksternal (mis. UptimeRobot) ke <span class="font-mono">/up</span> sebagai jaring pengaman.
    </p>
  </template>

  <!-- detail monitor -->
  <AppModal v-if="detail" :title="detail.monitor.name" :description="detail.monitor.url" size="lg" @close="detail = null">
    <AppSkeleton v-if="detail.loading" class="!h-48" />
    <div v-else class="grid gap-5">
      <div class="flex flex-wrap items-center gap-2">
        <AppBadge :tone="STATUS[detail.monitor.status].tone" dot>{{ STATUS[detail.monitor.status].label }}</AppBadge>
        <span class="text-sm text-muted">Berubah {{ detail.monitor.last_change_at ? relative(detail.monitor.last_change_at, now) : '—' }} · cek tiap {{ detail.monitor.interval_seconds }} detik</span>
      </div>
      <section class="grid gap-2">
        <h3 class="label">Waktu respons 24 jam</h3>
        <ResponseChart :checks="detail.checks" :interval="detail.monitor.interval_seconds" />
      </section>
      <section class="grid gap-2">
        <h3 class="label">Perubahan status</h3>
        <p v-if="!detail.events.length" class="text-sm text-muted">Belum ada.</p>
        <ul v-else class="grid gap-1 text-sm">
          <li v-for="(e, i) in detail.events" :key="i" class="flex flex-wrap gap-x-3 border-b border-line py-2 last:border-b-0">
            <span class="font-mono text-muted">{{ dateTime(e.at) }}</span>
            <span>{{ STATUS[e.from]?.label ?? e.from }} → <strong>{{ STATUS[e.to]?.label ?? e.to }}</strong></span>
            <span v-if="e.reason" class="text-muted">{{ e.reason }}</span>
          </li>
        </ul>
      </section>
    </div>
    <template #footer>
      <AppButton v-if="canManage" variant="ghost" @click="removing = detail.monitor"><Trash2 class="size-5 text-danger" /> Hapus</AppButton>
      <AppButton variant="secondary" :loading="busy" :disabled="!detail.monitor.is_active" @click="checkNow(detail.monitor)"><RefreshCw class="size-5" /> Cek sekarang</AppButton>
    </template>
  </AppModal>

  <!-- formulir monitor -->
  <AppModal v-if="editing" :title="editing === 'new' ? 'Monitor baru' : `Ubah ${editing.name}`" size="lg" @close="editing = null">
    <form id="monitor-form" class="grid gap-4" @submit.prevent="saveMonitor">
      <AppSegmented v-model="form.type" label="Jenis" :options="[{ value: 'http', label: 'HTTP / HTTPS' }, { value: 'ssl', label: 'Masa berlaku SSL' }]" />
      <div class="grid gap-4 sm:grid-cols-2">
        <AppField label="Nama" :error="errors.name?.[0]"><AppInput v-model="form.name" maxlength="80" required /></AppField>
        <AppField label="Alamat" :hint="form.type === 'ssl' ? 'Domain HTTPS yang sertifikatnya dicek' : 'URL lengkap, mis. https://app.domain/up'" :error="errors.url?.[0]">
          <AppInput v-model="form.url" type="url" inputmode="url" required />
        </AppField>
      </div>
      <div v-if="form.type === 'http'" class="grid gap-4 sm:grid-cols-3">
        <AppField label="Metode"><AppSelect v-model="form.method" :options="['GET', 'HEAD', 'POST'].map((v) => ({ value: v, label: v }))" /></AppField>
        <AppField label="Kode status diterima" hint="200-399 atau 200,301" :error="errors.expected_status?.[0]"><AppInput v-model="form.expected_status" code /></AppField>
        <AppField label="Kata kunci (opsional)" hint="Harus ada di isi halaman" :error="errors.keyword?.[0]"><AppInput v-model="form.keyword" maxlength="200" /></AppField>
      </div>
      <div class="grid gap-4 sm:grid-cols-3">
        <AppField label="Cek setiap" :error="errors.interval_seconds?.[0]"><AppSelect v-model="form.interval_seconds" :options="INTERVALS" /></AppField>
        <AppField label="Batas waktu (detik)" :error="errors.timeout_seconds?.[0]"><AppInput v-model="form.timeout_seconds" type="number" min="2" max="30" /></AppField>
        <AppField v-if="form.type === 'http'" label="Ulangi sebelum down" hint="Gagal berturut-turut tambahan" :error="errors.retries?.[0]"><AppInput v-model="form.retries" type="number" min="0" max="10" /></AppField>
        <AppField v-else label="Peringatan sebelum habis (hari)" :error="errors.ssl_warn_days?.[0]"><AppInput v-model="form.ssl_warn_days" type="number" min="1" max="90" /></AppField>
      </div>
      <AppToggle v-model="form.is_public" label="Tampilkan di halaman status publik" />
      <AppField v-if="form.is_public" label="Nama di halaman status" hint="Kosong = sama dengan nama monitor. Alamat & pesan error tidak pernah ditampilkan." :error="errors.public_name?.[0]">
        <AppInput v-model="form.public_name" maxlength="80" />
      </AppField>
      <AppToggle v-model="form.is_active" label="Aktif" />
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="editing = null">Batal</AppButton>
      <AppButton type="submit" form="monitor-form" :loading="busy">Simpan monitor</AppButton>
    </template>
  </AppModal>

  <AppModal v-if="removing" title="Hapus monitor?" size="sm" @close="removing = null">
    <p>Monitor <strong>{{ removing.name }}</strong> beserta riwayat ceknya dihapus permanen.</p>
    <template #footer>
      <AppButton variant="secondary" @click="removing = null">Batal</AppButton>
      <AppButton variant="danger" :loading="busy" @click="removeMonitor">Hapus</AppButton>
    </template>
  </AppModal>

  <!-- info gangguan / pembaruan -->
  <AppModal v-if="writing" :title="writing === 'new' ? 'Info baru' : `Pembaruan: ${writing.title}`" description="Langsung tampil di halaman status publik." @close="writing = null">
    <form id="incident-form" class="grid gap-4" @submit.prevent="saveIncident">
      <template v-if="writing === 'new'">
        <AppSegmented :model-value="incidentForm.kind" label="Jenis" :options="KIND" @update:model-value="setKind" />
        <AppField label="Judul" :error="incidentErrors.title?.[0]"><AppInput v-model="incidentForm.title" maxlength="150" placeholder="mis. Tap kartu lambat" required /></AppField>
        <AppField v-if="incidentForm.kind === 'incident'" label="Dampak"><AppSegmented v-model="incidentForm.impact" label="Dampak" :options="IMPACT" /></AppField>
        <div v-else class="grid gap-4 sm:grid-cols-2">
          <AppField label="Mulai (WIB)" :error="incidentErrors.starts_at?.[0]"><AppInput v-model="incidentForm.starts_at" type="datetime-local" /></AppField>
          <AppField label="Perkiraan selesai (WIB)" :error="incidentErrors.ends_at?.[0]"><AppInput v-model="incidentForm.ends_at" type="datetime-local" /></AppField>
        </div>
      </template>
      <AppField label="Status"><AppSelect v-model="incidentForm.status" :options="statusOptions(incidentForm.kind)" /></AppField>
      <AppField label="Pesan untuk pengguna" :error="incidentErrors.body?.[0]">
        <AppTextarea v-model="incidentForm.body" rows="4" maxlength="2000" placeholder="Apa yang terjadi, siapa yang terdampak, dan kapan diperkirakan normal." required />
      </AppField>
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="writing = null">Batal</AppButton>
      <AppButton type="submit" form="incident-form" :loading="busy">Publikasikan</AppButton>
    </template>
  </AppModal>
</template>
