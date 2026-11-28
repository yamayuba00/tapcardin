<script setup>
// M-03 / AFD F-04 — per kartu: tulis → verifikasi → kunci → lapor UID. Layar penuh hijau/merah + suara.
// Dua mode reader: HP (Web NFC, Chrome Android) atau Reader USB lewat konektor lokal tools/nfc-bridge (PC/SC).
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppCheckbox from '@/components/ui/AppCheckbox/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import StationScreen from '@/components/common/StationScreen/index.vue'
import { adminApi } from '@/api/admin'
import { useAsync } from '@/composables/useAsync'
import { beep } from '@/lib/feedback'
import { encodeUrl, nfcErrorMessage, nfcSupported } from '@/lib/nfc'
import { bridgeStatusText, createBridgeClient, safeStorage } from '@/lib/nfcBridge'

const route = useRoute()
const supported = nfcSupported()
const batch = ref(route.query.batch || '')
const card = ref(null)
const state = ref('idle') // idle | waiting | ok | fail | done
const message = ref('')
const lockAfter = ref(true)
const manualUid = ref('')
let abort

// mode reader diingat per browser (localStorage dibungkus try/catch lewat safeStorage)
const MODE_KEY = 'tapcard.encodeMode'
const store = safeStorage()
const MODES = [{ value: 'web', label: 'HP (Web NFC)' }, { value: 'usb', label: 'Reader USB' }]
const savedMode = store.get(MODE_KEY)
const mode = ref(savedMode === 'web' || savedMode === 'usb' ? savedMode : supported ? 'web' : 'usb')
const usb = computed(() => mode.value === 'usb')
const bridge = ref({ connected: false, paired: false, needsPairing: false, reader: null, card: false, canLock: false, error: '' })
const pairCode = ref('')
let client = null

function startBridge() {
  if (client) return
  client = createBridgeClient({ onChange: (s) => (bridge.value = s) })
  client.connect()
}
function stopBridge() {
  client?.close()
  client = null
}
watch(mode, (m) => {
  store.set(MODE_KEY, m)
  abort?.abort()
  if (state.value === 'fail' || state.value === 'waiting') { state.value = 'idle'; message.value = '' }
  m === 'usb' ? startBridge() : stopBridge()
}, { immediate: true })
onBeforeUnmount(stopBridge)

const bridgeText = computed(() => bridgeStatusText(bridge.value))
const bridgeReady = computed(() => bridge.value.connected && bridge.value.paired)

const { data: batches } = useAsync(async () => {
  const res = await adminApi.stationBatches()
  if (!batch.value && res.data[0]) batch.value = res.data[0].id
  return res
})
// ganti batch → muat kartu berikutnya dari batch itu (AppSelect tidak memancarkan `change`)
watch(batch, () => next(), { immediate: true })

async function next(after = 0) {
  state.value = 'idle'
  message.value = ''
  if (!batch.value) return
  card.value = (await adminApi.nextCard(batch.value, 'encode', after || null)).data
  if (!card.value) state.value = 'done'
}

async function report(uid) {
  await adminApi.encoded(card.value.token, uid)
  state.value = 'ok'
  message.value = `Kartu #${card.value.sequence} siap`
  beep(true)
  setTimeout(() => next(), 1200)
}

async function encode() {
  state.value = 'waiting'
  abort?.abort()
  try {
    const uid = await encodeUrl(card.value.url, {
      lockAfter: lockAfter.value,
      onStatus: (t) => (message.value = t),
      onRetry: () => beep(false),
      register: (c) => (abort = c),
    })
    await report(uid)
  } catch (e) {
    abort?.abort()
    state.value = 'fail'
    message.value = nfcErrorMessage(e)
    beep(false)
  }
}

async function encodeUsb() {
  state.value = 'waiting'
  message.value = 'Letakkan kartu di atas reader…'
  try {
    const uid = await client.write(card.value.url, card.value.token, {
      lock: lockAfter.value && bridge.value.canLock,
      onStatus: (t) => (message.value = t),
    })
    await report(uid)
  } catch (e) {
    if (!usb.value) return // mode diganti di tengah proses: koneksi sengaja ditutup
    state.value = 'fail'
    message.value = e?.message || 'Gagal memproses kartu.'
    beep(false)
  }
}

function pair() {
  client?.pair(pairCode.value)
  pairCode.value = ''
}

async function manual() {
  try { await report(manualUid.value) } catch (e) { state.value = 'fail'; message.value = e.message; beep(false) }
}
</script>

<template>
  <PageHeader eyebrow="Produksi" title="Stasiun encode">
    <AppSelect v-if="batches?.length" v-model="batch" :options="batches.map((b) => ({ value: b.id, label: `${b.code} · ${b.quantity} kartu · ${b.chip.toUpperCase()}` }))" class="!w-full sm:!w-96" aria-label="Batch" />
  </PageHeader>

  <div class="mb-4 flex flex-wrap items-center gap-3">
    <AppSegmented v-model="mode" :options="MODES" label="Mode reader" />
    <span v-if="usb" class="inline-flex items-center gap-2 text-sm" role="status">
      <span :class="['size-2.5 rounded-full', bridgeReady && bridge.reader ? 'bg-ok' : bridge.connected ? 'bg-warn' : 'bg-danger']" aria-hidden="true" />
      {{ bridgeText }}
    </span>
  </div>

  <div v-if="usb && !bridge.connected" class="mb-4 rounded-md border border-line-strong bg-surface-2 px-4 py-3 text-sm">
    Konektor NFC belum berjalan di PC ini. Colok reader USB (ACR122U / ACR1252U), lalu jalankan
    <code class="font-mono">npm start</code> di folder <code class="font-mono">tools/nfc-bridge</code>. Halaman ini menyambung otomatis.
  </div>
  <form v-else-if="usb && bridge.needsPairing" class="mb-4 grid gap-2 rounded-md border border-line-strong bg-surface-2 px-4 py-3 text-sm" @submit.prevent="pair">
    <label for="nfc-pair" class="font-medium">Kode pasangan konektor</label>
    <p class="text-muted">Masukkan 6 angka yang tampil di jendela konektor. Cukup sekali di browser ini.</p>
    <div class="flex flex-wrap gap-2">
      <AppInput id="nfc-pair" v-model="pairCode" inputmode="numeric" maxlength="6" autocomplete="off" placeholder="123456" class="max-w-40 font-mono" :invalid="!!bridge.error" />
      <AppButton type="submit" :disabled="!/^\d{6}$/.test(pairCode.trim())">Pasangkan</AppButton>
    </div>
    <p v-if="bridge.error" class="text-danger">{{ bridge.error }}</p>
  </form>

  <p v-if="!usb && !supported" class="rounded-md border border-line-strong bg-surface-2 px-4 py-3 text-sm">
    Web NFC hanya tersedia di Chrome Android lewat HTTPS. Di perangkat ini pilih Reader USB, gunakan input UID manual (untuk uji), atau buka halaman ini dari ponsel Android.
  </p>
  <p v-if="batches && !batches.length" class="text-muted">Belum ada batch yang siap di-encode. Buat batch dan generate token dulu.</p>

  <StationScreen :state="state === 'ok' ? 'ok' : state === 'fail' ? 'fail' : 'idle'">
    <p v-if="state === 'done'" class="font-display text-2xl">Semua kartu sudah di-encode</p>
    <div v-else-if="card" class="grid w-full min-w-0 max-w-xl justify-items-center gap-4">
      <span class="label !text-current opacity-80">Urutan · sisa {{ card.remaining }} dari {{ card.total }}</span>
      <span class="num text-[56px] leading-none sm:text-[64px]">#{{ card.sequence }}</span>
      <p class="font-display text-xl break-words sm:text-2xl" role="status">{{ message || card.holder || 'Stok' }}</p>
      <p class="max-w-full font-mono text-xs break-all opacity-80 sm:text-sm">{{ card.short_code }} · {{ card.url }}</p>
      <div class="flex flex-wrap justify-center gap-2">
        <AppButton v-if="usb" size="lg" :disabled="state === 'waiting' || !bridgeReady" @click="encodeUsb">{{ state === 'fail' ? 'Ulangi' : 'Tulis' }}</AppButton>
        <AppButton v-else-if="supported" size="lg" :disabled="state === 'waiting'" @click="encode">{{ state === 'fail' ? 'Ulangi' : 'Tulis' }}</AppButton>
        <AppButton variant="secondary" size="lg" @click="next(card.sequence)">Lewati</AppButton>
      </div>
      <template v-if="usb">
        <AppCheckbox v-if="bridge.canLock" v-model="lockAfter">Kunci chip permanen setelah verifikasi</AppCheckbox>
        <p v-else-if="bridgeReady" class="text-sm opacity-80">Chip tidak dikunci: penguncian nonaktif di konektor (jalankan dengan --allow-lock).</p>
      </template>
      <AppCheckbox v-else-if="supported" v-model="lockAfter">Kunci chip permanen setelah verifikasi</AppCheckbox>
      <form v-if="(usb && !bridgeReady) || (!usb && !supported)" class="flex w-full flex-wrap justify-center gap-2" @submit.prevent="manual">
        <AppInput v-model="manualUid" placeholder="UID chip, mis. 04A1B2C3D4E5F6" class="max-w-xs min-w-0" />
        <AppButton type="submit" variant="secondary" :disabled="manualUid.length < 8">Catat</AppButton>
      </form>
    </div>
  </StationScreen>
</template>
