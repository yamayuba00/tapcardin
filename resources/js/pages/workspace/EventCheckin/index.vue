<script setup>
// Check-in peserta event (panitia): tap NFC / scan QR kartu peserta, atau ketik kode kartu. Satu kali per kartu per event.
// Hasil langsung terlihat (berhasil / sudah check-in / tidak dikenal); daftar hadir terbaru diperbarui tiap 10 detik.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { Camera, CircleAlert, CircleCheck, Keyboard, Nfc, RotateCcw, X } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import TapZone from '@/components/common/TapZone/index.vue'
import { checkinsApi, eventsApi } from '@/api/workspace'
import { nfcSupported, readOnce } from '@/lib/nfc'
import { qrSupported, scanQr } from '@/lib/scanner'
import { relative } from '@/lib/format'

const route = useRoute()
const id = route.params.id
const event = ref(null)
const recent = ref([])
const count = ref(0)
const canNfc = nfcSupported()
const canQr = ref(false)
const video = ref(null)
const mode = ref(null)
const state = ref('idle') // idle | waiting | ok | fail
const message = ref('')
const result = ref(null) // {status, checkin}
const manual = ref('')
let ctrl = null
let timer = null

const load = () => checkinsApi.list(id, { per_page: 10 }).then((r) => { recent.value = r.data; count.value = r.meta.total }).catch(() => {})
onMounted(async () => {
  event.value = (await eventsApi.get(id)).data
  canQr.value = await qrSupported().catch(() => false)
  load()
  timer = setInterval(load, 10_000)
})
onBeforeUnmount(() => { ctrl?.abort(); clearInterval(timer) })

function stop() {
  ctrl?.abort()
  ctrl = null
  mode.value = null
  if (state.value === 'waiting') state.value = 'idle'
}
async function read(kind) {
  stop()
  mode.value = kind
  state.value = 'waiting'
  result.value = null
  message.value = kind === 'nfc' ? 'Tempelkan kartu peserta ke bagian belakang ponsel…' : 'Arahkan kamera ke QR kartu peserta…'
  ctrl = new AbortController()
  try {
    const value = kind === 'nfc' ? (await readOnce({ signal: ctrl.signal })).url : await scanQr(video.value, { signal: ctrl.signal })
    if (!value) throw new Error('Kartu terbaca, tapi tidak berisi tautan.')
    await checkin(value, kind)
  } catch (e) {
    if (e.name !== 'AbortError') fail(e.name === 'NotAllowedError' ? 'Izin kamera ditolak. Aktifkan izin kamera untuk situs ini.' : e.message)
  } finally {
    mode.value = null
  }
}
function fail(msg) {
  state.value = 'fail'
  message.value = msg
  navigator.vibrate?.([80, 60, 80])
}
async function checkin(value, method) {
  state.value = 'waiting'
  message.value = 'Mencatat kehadiran…'
  try {
    const res = await checkinsApi.create(id, value, method)
    result.value = res.data
    count.value = res.data.count
    state.value = res.data.status === 'checked_in' ? 'ok' : 'fail'
    message.value = ''
    navigator.vibrate?.(res.data.status === 'checked_in' ? 60 : [80, 60, 80])
    manual.value = ''
    load()
  } catch (e) {
    fail(e.message)
  }
}
const submitManual = () => manual.value.trim() && checkin(manual.value.trim(), 'code')
const fresh = computed(() => result.value?.status === 'checked_in')
</script>

<template>
  <PageHeader :title="event?.name ?? 'Check-in'" eyebrow="Check-in peserta" :back="`/events/${id}`" back-label="Event">
    <template #meta><span class="text-sm text-muted"><strong class="font-mono text-fg">{{ count }}</strong> peserta hadir</span></template>
  </PageHeader>

  <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
    <AppPanel>
      <div class="grid gap-5">
        <div v-if="result" :class="['flex items-center gap-3 rounded-md border px-4 py-3', fresh ? 'border-ok' : 'border-warn']" aria-live="polite">
          <component :is="fresh ? CircleCheck : CircleAlert" :class="['size-8 flex-none', fresh ? 'text-ok' : 'text-warn']" aria-hidden="true" />
          <div class="min-w-0 flex-1">
            <p class="text-lg font-semibold">{{ result.checkin.name }}</p>
            <p class="text-sm text-muted">
              {{ fresh ? 'Berhasil check-in' : `Sudah check-in ${relative(result.checkin.at)}` }}{{ result.checkin.guest ? ' · tamu dari workspace lain' : '' }}
            </p>
          </div>
        </div>
        <TapZone v-else :state="state" />
        <p v-if="message" :class="['text-center', state === 'fail' ? 'text-danger' : 'text-muted']" aria-live="polite">{{ message }}</p>
        <video v-show="mode === 'qr'" ref="video" class="mx-auto aspect-square w-full max-w-sm rounded-md bg-surface-2 object-cover" playsinline muted></video>

        <div class="grid gap-2 sm:flex sm:flex-wrap sm:justify-center">
          <AppButton variant="ink" v-if="canNfc" size="lg" :disabled="mode === 'nfc'" @click="read('nfc')"><Nfc class="size-5" /> Tap kartu</AppButton>
          <AppButton v-if="canQr" size="lg" :variant="canNfc ? 'secondary' : 'ink'" :disabled="mode === 'qr'" @click="read('qr')"><Camera class="size-5" /> Scan QR</AppButton>
          <AppButton v-if="mode" size="lg" variant="ghost" @click="stop"><X class="size-5" /> Berhenti</AppButton>
          <AppButton v-if="result && !mode" size="lg" variant="ghost" @click="result = null; state = 'idle'"><RotateCcw class="size-5" /> Peserta berikutnya</AppButton>
        </div>
        <p v-if="!canNfc && !canQr" class="text-center text-sm text-muted">
          Perangkat ini belum bisa membaca NFC/QR dari browser (NFC hanya di Chrome Android). Ketik kode yang tercetak di kartu peserta.
        </p>

        <form class="grid gap-2 border-t border-line pt-5" @submit.prevent="submitManual">
          <AppField label="Kode kartu atau tautan" hint="Contoh: TC·7K2M-9QXP">
            <div class="flex gap-2">
              <AppInput v-model="manual" class="min-w-0 flex-1" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="XXXX-XXXX atau URL" />
              <AppButton type="submit" variant="secondary" :disabled="!manual.trim()"><Keyboard class="size-5" /> Check-in</AppButton>
            </div>
          </AppField>
        </form>
      </div>
    </AppPanel>

    <AppPanel title="Baru saja hadir" flush>
      <AppSkeleton v-if="!event" class="m-5 !h-24" />
      <ul v-else>
        <li v-for="c in recent" :key="c.id" class="flex items-center gap-3 border-t border-line px-5 py-3 text-sm first:border-t-0">
          <span class="min-w-0 flex-1 truncate font-medium">{{ c.name }}<span v-if="c.guest" class="text-muted"> · tamu</span></span>
          <span class="flex-none text-muted">{{ relative(c.at) }}</span>
        </li>
        <li v-if="!recent.length" class="px-5 py-4 text-sm text-muted">Belum ada peserta yang check-in.</li>
      </ul>
    </AppPanel>
  </div>
</template>
