<script setup>
// Editor email blast: subjek, preheader, audiens, isi markdown (toolbar + unggah gambar), pratinjau langsung dari
// renderer server (sama persis dengan email terkirim), kirim uji, jadwal, kirim sekarang (konfirmasi jumlah penerima), batal.
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  Bold, CalendarClock, Heading2, Image as ImageIcon, Italic, Link2, List, Minus, RectangleHorizontal, Save, Send, SendHorizontal, XCircle,
} from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppMeter from '@/components/ui/AppMeter/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import AppTextarea from '@/components/ui/AppTextarea/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { useAction, useAsync } from '@/composables/useAsync'
import { dateTime, num } from '@/lib/format'
import { AUDIENCE, STATUS, button as mdButton, image as mdImage, insertBlock, isHttps, link as mdLink, prefixLines, progress, wrap } from '@/lib/markdownEdit'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToastStore()
const { busy, run } = useAction()

const STARTER = '# Judul promo\n\nHalo! Ceritakan penawaran Anda di sini dengan **teks tebal** atau _miring_.\n\n- Manfaat pertama\n- Manfaat kedua\n\n[[Tombol: Lihat penawaran | https://tapcard.id]]\n'
const form = reactive({ subject: '', preheader: '', audience: 'all', plan: '', body: STARTER })
const blast = ref(null)
const canTest = ref(false)
const loading = ref(false)
const errors = ref({})
const saved = ref('')
const id = computed(() => blast.value?.id ?? null)
const payload = () => ({ ...form, preheader: form.preheader || null, plan: form.audience === 'plan' ? form.plan : null })
const dirty = computed(() => JSON.stringify(payload()) !== saved.value)
const editable = computed(() => !blast.value || ['draft', 'scheduled'].includes(blast.value.status))

const { data: plans } = useAsync(() => (auth.can('plans.manage') ? adminApi.plans() : Promise.resolve({ data: [] })))
const planOptions = computed(() => (plans.value ?? []).map((p) => ({ value: p.key, label: p.name })))
const audienceOptions = Object.entries(AUDIENCE).map(([value, label]) => ({ value, label }))

async function load() {
  if (route.params.id === 'new') {
    const meta = await adminApi.emailBlasts({ per_page: 1 }).catch(() => null)
    canTest.value = Boolean(meta?.meta?.can_test)
    return
  }
  loading.value = true
  try {
    const res = await adminApi.emailBlast(route.params.id)
    fill(res.data)
    canTest.value = Boolean(res.meta?.can_test)
  } catch (e) {
    toast.error(e.message)
  } finally {
    loading.value = false
  }
}
function fill(b) {
  blast.value = b
  Object.assign(form, { subject: b.subject, preheader: b.preheader ?? '', audience: b.audience, plan: b.plan ?? '', body: b.body ?? form.body })
  saved.value = JSON.stringify(payload())
}
load()

/** Simpan draf (buat baru bila belum ada). Mengembalikan id blast. */
async function save(quiet = false) {
  errors.value = {}
  try {
    const res = await run(() => (id.value ? adminApi.updateEmailBlast(id.value, payload()) : adminApi.createEmailBlast(payload())), quiet ? null : 'Draf disimpan.')
    const isNew = !id.value
    fill(res.data)
    if (isNew) router.replace(`/email-blasts/${res.data.id}`)
    return res.data.id
  } catch (e) {
    errors.value = e.fields ?? {}
    return null
  }
}
const ensureSaved = async () => (id.value && !dirty.value ? id.value : save(true))

// ── pratinjau (renderer server, ditunda 400 md setelah berhenti mengetik) ─────────────────────────────
const previewUrl = ref('')
let previewTimer = null
let previewSeq = 0
watch(() => [form.subject, form.preheader, form.body], () => {
  clearTimeout(previewTimer)
  previewTimer = setTimeout(async () => {
    const mine = ++previewSeq
    try {
      const res = await adminApi.previewEmailBlast({ subject: form.subject, preheader: form.preheader, body: form.body })
      if (mine === previewSeq) previewUrl.value = res.data.url
    } catch { /* pratinjau gagal: biarkan yang lama */ }
  }, 400)
}, { immediate: true })
onBeforeUnmount(() => clearTimeout(previewTimer))

// ── toolbar markdown ─────────────────────────────────────────────────────────────────────────────
const editorEl = ref(null)
const textarea = () => editorEl.value?.querySelector('textarea')
function apply(fn) {
  const el = textarea()
  const start = el?.selectionStart ?? form.body.length
  const end = el?.selectionEnd ?? form.body.length
  const r = fn(form.body, start, end)
  form.body = r.text
  requestAnimationFrame(() => { const t = textarea(); if (t) { t.focus(); t.setSelectionRange(r.start, r.end) } })
}
const tools = [
  { label: 'Tebal', icon: Bold, run: () => apply((t, s, e) => wrap(t, s, e, '**')) },
  { label: 'Miring', icon: Italic, run: () => apply((t, s, e) => wrap(t, s, e, '_')) },
  { label: 'Judul', icon: Heading2, run: () => apply((t, s, e) => prefixLines(t, s, e, '## ')) },
  { label: 'Daftar', icon: List, run: () => apply((t, s, e) => prefixLines(t, s, e, '- ')) },
  { label: 'Tautan', icon: Link2, run: () => openDialog('link') },
  { label: 'Gambar', icon: ImageIcon, run: () => fileEl.value?.click() },
  { label: 'Tombol', icon: RectangleHorizontal, run: () => openDialog('button') },
  { label: 'Garis pemisah', icon: Minus, run: () => apply((t, s, e) => insertBlock(t, s, e, '---')) },
]

const dialog = ref(null) // link | button | test | schedule | send | cancel
const dlg = reactive({ url: 'https://', label: '', email: '', when: '', count: null, error: '' })
function openDialog(kind) {
  Object.assign(dlg, { url: 'https://', label: '', error: '' })
  if (kind === 'button') dlg.label = 'Lihat penawaran'
  dialog.value = kind
}
function insertLinkOrButton() {
  if (!isHttps(dlg.url)) { dlg.error = 'Gunakan alamat lengkap yang diawali https://'; return }
  if (dialog.value === 'button') {
    if (!dlg.label.trim()) { dlg.error = 'Isi teks tombol.'; return }
    apply((t, s, e) => mdButton(t, s, e, dlg.label, dlg.url))
  } else apply((t, s, e) => mdLink(t, s, e, dlg.url))
  dialog.value = null
}

const fileEl = ref(null)
const uploading = ref(false)
async function onFile(ev) {
  const file = ev.target.files?.[0]
  ev.target.value = ''
  if (!file) return
  if (file.size > 2 * 1024 * 1024) { toast.error('Ukuran gambar maksimal 2 MB.'); return }
  uploading.value = true
  try {
    const res = await adminApi.uploadBlastImage(file)
    const alt = file.name.replace(/\.[a-z0-9]+$/i, '').replace(/[-_]+/g, ' ').trim() || 'Gambar'
    apply((t, s, e) => mdImage(t, s, e, res.data.url, alt))
    toast.ok('Gambar diunggah.')
  } catch (e) {
    toast.error(e.message)
  } finally {
    uploading.value = false
  }
}

// ── aksi kirim ────────────────────────────────────────────────────────────────────────────────────
async function openTest() {
  dlg.email = auth.me?.email ?? ''
  dlg.error = ''
  dialog.value = 'test'
}
async function sendTest() {
  const bid = await ensureSaved()
  if (!bid) return
  try {
    await run(() => adminApi.testEmailBlast(bid, dlg.email), `Email uji dikirim ke ${dlg.email}.`)
    dialog.value = null
  } catch (e) { dlg.error = e.fields?.email?.[0] ?? '' }
}
function openSchedule() {
  const d = blast.value?.scheduled_at ? new Date(blast.value.scheduled_at) : new Date(Date.now() + 3600e3)
  const pad = (n) => String(n).padStart(2, '0')
  dlg.when = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
  dlg.error = ''
  dialog.value = 'schedule'
}
async function schedule() {
  if (!dlg.when) { dlg.error = 'Pilih waktu kirim.'; return }
  const bid = await ensureSaved()
  if (!bid) return
  try {
    const res = await run(() => adminApi.scheduleEmailBlast(bid, new Date(dlg.when).toISOString()), 'Blast dijadwalkan.')
    fill(res.data)
    dialog.value = null
  } catch (e) { dlg.error = e.fields?.scheduled_at?.[0] ?? '' }
}
async function openSend() {
  dlg.count = null
  dialog.value = 'send'
  try {
    const res = await adminApi.emailBlastCount({ audience: form.audience, ...(form.audience === 'plan' ? { plan: form.plan } : {}) })
    dlg.count = res.data.count
  } catch (e) { dialog.value = null; toast.error(e.message) }
}
async function sendNow() {
  const bid = await ensureSaved()
  if (!bid) { dialog.value = null; return }
  try {
    const res = await run(() => adminApi.sendEmailBlast(bid), 'Blast masuk antrean kirim.')
    fill(res.data)
  } catch { /* toast sudah tampil */ }
  dialog.value = null
}
async function cancelBlast() {
  try {
    const res = await run(() => adminApi.cancelEmailBlast(id.value), 'Blast dibatalkan.')
    fill(res.data)
  } catch { /* toast */ }
  dialog.value = null
}

// progres: muat ulang selama status masih mengirim
let pollTimer = null
watch(() => blast.value?.status, (s) => {
  clearTimeout(pollTimer)
  if (s === 'sending') pollTimer = setTimeout(async function poll() {
    const res = await adminApi.emailBlast(id.value).catch(() => null)
    if (res) { blast.value = { ...blast.value, ...res.data } }
    if (blast.value?.status === 'sending') pollTimer = setTimeout(poll, 5000)
  }, 5000)
})
onBeforeUnmount(() => clearTimeout(pollTimer))
</script>

<template>
  <PageHeader back="/email-blasts" back-label="Email blast" :title="blast ? blast.subject : 'Blast baru'">
    <template #meta>
      <div v-if="blast" class="flex flex-wrap items-center gap-2 text-sm text-muted">
        <AppBadge :tone="STATUS[blast.status]?.tone">{{ STATUS[blast.status]?.label }}</AppBadge>
        <span v-if="blast.status === 'scheduled'">Dikirim {{ dateTime(blast.scheduled_at) }}</span>
        <span v-if="dirty && editable">· Ada perubahan belum disimpan</span>
      </div>
    </template>
    <template v-if="editable">
      <AppButton variant="secondary" :loading="busy && !dialog" @click="save()"><Save class="size-5" /> Simpan draf</AppButton>
      <AppButton v-if="canTest" variant="secondary" @click="openTest"><SendHorizontal class="size-5" /> Kirim uji</AppButton>
      <AppButton variant="secondary" @click="openSchedule"><CalendarClock class="size-5" /> Jadwalkan</AppButton>
      <AppButton @click="openSend"><Send class="size-5" /> Kirim sekarang</AppButton>
    </template>
    <AppButton v-if="blast && ['scheduled', 'sending'].includes(blast.status)" variant="danger" @click="dialog = 'cancel'"><XCircle class="size-5" /> Batalkan</AppButton>
  </PageHeader>

  <AppSkeleton v-if="loading" class="h-96" />
  <template v-else>
    <AppPanel v-if="blast && blast.counts.total" title="Progres kirim">
      <AppMeter :value="progress(blast.counts)" :max="100" plain :label="`${progress(blast.counts)}%`">
        {{ num(blast.counts.sent) }} terkirim · {{ num(blast.counts.failed) }} gagal · {{ num(blast.counts.skipped) }} dilewati · {{ num(blast.counts.total) }} total
      </AppMeter>
    </AppPanel>

    <div class="grid gap-4 xl:grid-cols-2">
      <AppPanel title="Isi email">
        <fieldset class="grid gap-4" :disabled="!editable">
          <AppField label="Subjek" :hint="`${form.subject.length}/150`" :error="errors.subject?.[0]"><AppInput v-model="form.subject" maxlength="150" required placeholder="Mis. Diskon 30% kartu NFC akhir tahun" /></AppField>
          <AppField label="Preheader" hint="Teks pratinjau di kotak masuk (opsional)" :error="errors.preheader?.[0]"><AppInput v-model="form.preheader" maxlength="200" /></AppField>
          <div class="grid gap-4 sm:grid-cols-2">
            <AppField label="Penerima" :error="errors.audience?.[0]"><AppSelect v-model="form.audience" :options="audienceOptions" /></AppField>
            <AppField v-if="form.audience === 'plan'" label="Paket" :error="errors.plan?.[0]"><AppSelect v-model="form.plan" :options="planOptions" placeholder="Pilih paket" /></AppField>
          </div>
          <AppField label="Isi (Markdown)" :error="errors.body?.[0]">
            <div ref="editorEl" class="grid gap-2">
              <div class="flex flex-wrap gap-1 rounded-md border border-line bg-surface-2 p-1" role="toolbar" aria-label="Format isi">
                <AppButton v-for="t in tools" :key="t.label" variant="ghost" icon :aria-label="t.label" :title="t.label" :loading="t.icon === ImageIcon && uploading" @click="t.run">
                  <component :is="t.icon" class="size-5" />
                </AppButton>
              </div>
              <AppTextarea v-model="form.body" rows="18" class="font-mono !text-sm" spellcheck="true" />
              <p class="text-xs text-muted">Tombol: <code>[[Tombol: Teks | https://alamat]]</code>. Tautan & tombol wajib https. HTML mentah tidak didukung.</p>
            </div>
          </AppField>
          <input ref="fileEl" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onFile">
        </fieldset>
      </AppPanel>

      <AppPanel title="Pratinjau" flush>
        <iframe v-if="previewUrl" :src="previewUrl" title="Pratinjau email" class="block h-[520px] w-full border-0 bg-surface-2 xl:h-[760px]"></iframe>
      </AppPanel>
    </div>
  </template>

  <AppModal v-if="dialog === 'link' || dialog === 'button'" size="sm" :title="dialog === 'button' ? 'Sisipkan tombol' : 'Sisipkan tautan'" @close="dialog = null">
    <form id="dlg-link" class="grid gap-4" @submit.prevent="insertLinkOrButton">
      <AppField v-if="dialog === 'button'" label="Teks tombol"><AppInput v-model="dlg.label" maxlength="60" /></AppField>
      <AppField label="Alamat (https)" :error="dlg.error"><AppInput v-model="dlg.url" type="url" inputmode="url" /></AppField>
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="dialog = null">Batal</AppButton>
      <AppButton type="submit" form="dlg-link">Sisipkan</AppButton>
    </template>
  </AppModal>

  <AppModal v-if="dialog === 'test'" size="sm" title="Kirim email uji" description="Draf saat ini dikirim ke satu alamat, dengan awalan [UJI] di subjek. Tidak membuat daftar penerima." @close="dialog = null">
    <form id="dlg-test" @submit.prevent="sendTest">
      <AppField label="Kirim ke" :error="dlg.error"><AppInput v-model="dlg.email" type="email" required /></AppField>
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="dialog = null">Batal</AppButton>
      <AppButton type="submit" form="dlg-test" :loading="busy">Kirim uji</AppButton>
    </template>
  </AppModal>

  <AppModal v-if="dialog === 'schedule'" size="sm" title="Jadwalkan blast" description="Blast dikirim otomatis saat waktunya tiba. Masih bisa diubah atau dibatalkan sebelum itu." @close="dialog = null">
    <form id="dlg-schedule" @submit.prevent="schedule">
      <AppField label="Waktu kirim (waktu perangkat ini)" :error="dlg.error"><AppInput v-model="dlg.when" type="datetime-local" required /></AppField>
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="dialog = null">Batal</AppButton>
      <AppButton type="submit" form="dlg-schedule" :loading="busy">Jadwalkan</AppButton>
    </template>
  </AppModal>

  <AppModal v-if="dialog === 'send'" size="sm" title="Kirim sekarang?" @close="dialog = null">
    <p v-if="dlg.count === null" class="text-muted">Menghitung penerima…</p>
    <p v-else-if="dlg.count === 0">Tidak ada penerima untuk audiens ini.</p>
    <p v-else>“{{ form.subject }}” akan dikirim ke <strong class="font-mono">{{ num(dlg.count) }}</strong> alamat ({{ AUDIENCE[form.audience].toLowerCase() }}). Pengiriman bertahap dan tidak bisa ditarik kembali; yang belum terkirim masih bisa dibatalkan.</p>
    <template #footer>
      <AppButton variant="secondary" @click="dialog = null">Batal</AppButton>
      <AppButton :loading="busy" :disabled="!dlg.count" @click="sendNow"><Send class="size-5" /> Kirim ke {{ dlg.count ? num(dlg.count) : '…' }} penerima</AppButton>
    </template>
  </AppModal>

  <AppModal v-if="dialog === 'cancel'" size="sm" title="Batalkan blast?" description="Email yang belum terkirim tidak akan dikirim. Yang sudah terkirim tidak bisa ditarik." @close="dialog = null">
    <template #footer>
      <AppButton variant="secondary" @click="dialog = null">Kembali</AppButton>
      <AppButton variant="danger" :loading="busy" @click="cancelBlast">Batalkan blast</AppButton>
    </template>
  </AppModal>
</template>
