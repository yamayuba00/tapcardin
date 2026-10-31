<script setup>
// U-10 / AFD F-09 — Mode Reader: baca kartu orang lain lewat NFC (Chrome Android), kamera QR, atau kode/URL.
// Kartu TapCard → data diambil dari bio-nya; kartu lain → simpan URL + nama yang diketik. Lalu tambah catatan & tag.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Camera, CircleCheck, Keyboard, Nfc, Radio, UserPlus, X } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppTextarea from '@/components/ui/AppTextarea/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import StageBadge from '@/components/common/StageBadge/index.vue'
import TapZone from '@/components/common/TapZone/index.vue'
import { eventsApi, leadsApi, scansApi } from '@/api/workspace'
import { nfcSupported, readOnce } from '@/lib/nfc'
import { qrSupported, scanQr } from '@/lib/scanner'
import { relative } from '@/lib/format'
import { useToastStore } from '@/stores/toast'

const toast = useToastStore()
const canNfc = nfcSupported()
const canQr = ref(false)
const mode = ref(null) // 'nfc' | 'qr' | null
const state = ref('idle') // idle | waiting | ok | fail
const message = ref('')
const manual = ref('')
const external = ref(null) // nilai hasil scan yang bukan TapCard → minta nama
const extName = ref('')
const result = ref(null) // { kind, duplicate, connection }
const note = ref('')
const tags = ref('')
const inbound = ref([])
const activeEvent = ref(null)
const video = ref(null)
let ctrl = null

onMounted(async () => {
  canQr.value = await qrSupported().catch(() => false)
  loadInbound()
  eventsApi.list({ per_page: 10 }).then((r) => (activeEvent.value = r.meta.active)).catch(() => {})
})
onBeforeUnmount(() => ctrl?.abort())
const loadInbound = () => scansApi.inbound().then((r) => (inbound.value = r.data)).catch(() => {})

function stop() {
  ctrl?.abort()
  ctrl = null
  mode.value = null
  if (state.value === 'waiting') state.value = 'idle'
}
async function startNfc() {
  stop()
  mode.value = 'nfc'
  state.value = 'waiting'
  message.value = 'Tempelkan kartu ke bagian belakang ponsel…'
  ctrl = new AbortController()
  try {
    const { url } = await readOnce({ signal: ctrl.signal })
    if (!url) throw new Error('Kartu terbaca, tapi tidak berisi tautan.')
    await save(url)
  } catch (e) {
    if (e.name !== 'AbortError') fail(e.message)
  } finally {
    mode.value = null
  }
}
async function startQr() {
  stop()
  mode.value = 'qr'
  state.value = 'waiting'
  message.value = 'Arahkan kamera ke QR code kartu…'
  ctrl = new AbortController()
  try {
    const value = await scanQr(video.value, { signal: ctrl.signal })
    if (value) await save(value)
  } catch (e) {
    if (e.name !== 'AbortError') fail(e.name === 'NotAllowedError' ? 'Izin kamera ditolak. Aktifkan izin kamera untuk situs ini.' : e.message)
  } finally {
    mode.value = null
  }
}
function fail(msg) {
  state.value = 'fail'
  message.value = msg
}

async function save(value, name = null) {
  state.value = 'waiting'
  message.value = 'Menyimpan…'
  try {
    const res = await scansApi.save({ value, name })
    result.value = res.data
    external.value = null
    state.value = 'ok'
    message.value = res.data.duplicate ? `${res.data.connection.name} sudah ada di koneksi Anda — scan dicatat sebagai aktivitas.` : `${res.data.connection.name} tersimpan.`
    navigator.vibrate?.(60)
    loadInbound()
  } catch (e) {
    if (e.code === 'name_required') {
      external.value = value
      state.value = 'idle'
      message.value = ''
      return
    }
    fail(e.message)
  }
}
const submitManual = () => manual.value.trim() && save(manual.value.trim())
const submitExternal = () => extName.value.trim() && save(external.value, extName.value.trim())

async function saveDetails() {
  const c = result.value.connection
  const body = {}
  const t = tags.value.split(',').map((x) => x.trim()).filter(Boolean)
  if (t.length) body.tags = [...new Set([...(c.tags || []), ...t])]
  try {
    if (Object.keys(body).length) await leadsApi.update(c.id, body)
    if (note.value.trim()) await leadsApi.activity(c.id, 'note', note.value.trim())
    toast.ok('Catatan & tag disimpan')
    reset()
  } catch (e) {
    toast.error(e.message)
  }
}
function reset() {
  result.value = null
  note.value = ''
  tags.value = ''
  manual.value = ''
  extName.value = ''
  state.value = 'idle'
  message.value = ''
}
const pending = computed(() => inbound.value.filter((i) => !i.saved_back && i.card))
</script>

<template>
  <PageHeader eyebrow="Mode Reader" title="Scan kartu" />

  <p v-if="activeEvent" class="flex items-center gap-2 text-sm text-muted"><Radio class="size-4 text-accent-text" /> Hasil scan diberi label event <strong class="text-fg">{{ activeEvent.name }}</strong>.</p>

  <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
    <div class="grid gap-4">
      <!-- hasil -->
      <AppPanel v-if="result" :title="result.duplicate ? 'Sudah ada di koneksi' : 'Koneksi tersimpan'">
        <div class="grid gap-4">
          <div class="flex items-center gap-3">
            <CircleCheck class="size-8 flex-none text-ok" aria-hidden="true" />
            <div class="min-w-0 flex-1">
              <RouterLink :to="`/connections/${result.connection.id}`" class="text-lg font-semibold">{{ result.connection.name }}</RouterLink>
              <span class="block text-sm text-muted">{{ [result.connection.job_title, result.connection.company].filter(Boolean).join(' · ') || (result.kind === 'external' ? 'Kartu non-TapCard' : '') }}</span>
            </div>
            <StageBadge :stage="result.connection.stage" />
          </div>
          <AppField label="Catatan pertemuan"><AppTextarea v-model="note" rows="2" placeholder="mis. Tertarik paket korporat, minta proposal Senin" /></AppField>
          <AppField label="Tag" hint="Pisahkan dengan koma."><AppInput v-model="tags" placeholder="mis. vip, expo" /></AppField>
          <div class="flex flex-wrap gap-2">
            <AppButton @click="saveDetails">Simpan & scan berikutnya</AppButton>
            <AppButton variant="secondary" @click="reset">Lewati</AppButton>
          </div>
        </div>
      </AppPanel>

      <!-- kartu non-TapCard: minta nama -->
      <AppPanel v-else-if="external" title="Bukan kartu TapCard">
        <form class="grid gap-4" @submit.prevent="submitExternal">
          <p class="text-sm text-muted">Isinya: <span class="font-mono break-all text-fg">{{ external }}</span>. Ketik nama orangnya untuk menyimpan sebagai koneksi.</p>
          <AppField label="Nama *"><AppInput v-model="extName" maxlength="120" required autofocus /></AppField>
          <div class="flex gap-2"><AppButton type="submit" :disabled="!extName.trim()"><UserPlus class="size-5" /> Simpan</AppButton><AppButton variant="secondary" @click="reset">Batal</AppButton></div>
        </form>
      </AppPanel>

      <!-- pembaca -->
      <AppPanel v-else>
        <div class="grid gap-5">
          <TapZone :state="state" />
          <p v-if="message" :class="['text-center', state === 'fail' ? 'text-danger' : 'text-muted']" aria-live="polite">{{ message }}</p>
          <video v-show="mode === 'qr'" ref="video" class="mx-auto aspect-square w-full max-w-sm rounded-md bg-surface-2 object-cover" playsinline muted></video>

          <div class="flex flex-wrap justify-center gap-2">
            <AppButton v-if="canNfc" size="lg" :disabled="mode === 'nfc'" @click="startNfc"><Nfc class="size-5" /> Tap kartu</AppButton>
            <AppButton v-if="canQr" size="lg" :variant="canNfc ? 'secondary' : 'primary'" :disabled="mode === 'qr'" @click="startQr"><Camera class="size-5" /> Scan QR</AppButton>
            <AppButton v-if="mode" size="lg" variant="ghost" @click="stop"><X class="size-5" /> Berhenti</AppButton>
          </div>
          <p v-if="!canNfc && !canQr" class="text-center text-sm text-muted">
            Perangkat ini belum bisa membaca NFC/QR dari browser (NFC hanya di Chrome Android). Pakai kamera bawaan ponsel untuk scan QR, lalu salin tautannya ke kolom di bawah — atau ketik kode yang tercetak di kartu.
          </p>

          <form class="grid gap-2 border-t border-line pt-5" @submit.prevent="submitManual">
            <AppField label="Kode kartu, tautan, atau isi QR" hint="Contoh: TC·7K2M-9QXP, atau https://tap.…/c/…">
              <div class="flex gap-2">
                <AppInput v-model="manual" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="XXXX-XXXX atau URL" />
                <AppButton type="submit" variant="secondary" :disabled="!manual.trim()"><Keyboard class="size-5" /> Simpan</AppButton>
              </div>
            </AppField>
          </form>
        </div>
      </AppPanel>
    </div>

    <AppPanel title="Menyimpan Anda">
      <p class="mb-3 text-sm text-muted">Pengguna TapCard yang men-scan kartu Anda. Simpan balik dengan sekali ketuk.</p>
      <ul v-if="inbound.length" class="grid">
        <li v-for="i in inbound" :key="i.card || i.at" class="flex items-center gap-3 border-b border-line py-3 last:border-b-0">
          <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ i.name }}</span><span class="text-xs text-muted">{{ relative(i.at) }}</span></span>
          <span v-if="i.saved_back" class="inline-flex items-center gap-1 text-sm text-ok"><CircleCheck class="size-4" /> Tersimpan</span>
          <AppButton v-else-if="i.card" variant="secondary" @click="save(i.card)"><UserPlus class="size-5" /> Simpan balik</AppButton>
        </li>
      </ul>
      <p v-else class="text-sm text-muted">Belum ada.</p>
      <p v-if="pending.length" class="mt-3 text-xs text-muted">{{ pending.length }} belum disimpan balik.</p>
    </AppPanel>
  </div>
</template>
