<script setup>
// M-04 / PR-04 — tap kartu (NFC) + scan QR: keduanya harus menunjuk token kartu yang sedang diperiksa.
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppCheckbox from '@/components/ui/AppCheckbox/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import StationScreen from '@/components/common/StationScreen/index.vue'
import { adminApi } from '@/api/admin'
import { config } from '@/config'
import { useAsync } from '@/composables/useAsync'
import { beep } from '@/lib/feedback'
import { nfcSupported, readOnce } from '@/lib/nfc'
import { qrSupported, scanQr } from '@/lib/scanner'

const route = useRoute()
const canNfc = nfcSupported()
const canQr = ref(false)
const batch = ref(route.query.batch || '')
const card = ref(null)
const nfcUrl = ref('')
const qrUrl = ref('')
const state = ref('idle')
const message = ref('')
const revoke = ref(false)
const video = ref(null)
const scanning = ref(false)
let abort

const { data: batches } = useAsync(async () => {
  const res = await adminApi.stationBatches()
  if (!batch.value && res.data[0]) batch.value = res.data[0].id
  next()
  return res
})
onMounted(async () => (canQr.value = await qrSupported().catch(() => false)))

async function next(after = 0) {
  state.value = 'idle'
  message.value = ''
  nfcUrl.value = ''
  qrUrl.value = ''
  if (!batch.value) return
  card.value = (await adminApi.nextCard(batch.value, 'qc', after || null)).data
  if (!card.value) state.value = 'done'
}

async function readNfc() {
  abort?.abort()
  abort = new AbortController()
  message.value = 'Tempel kartu…'
  try {
    nfcUrl.value = (await readOnce({ signal: abort.signal })).url || ''
    abort.abort()
    message.value = 'NFC terbaca. Sekarang scan QR.'
    if (canQr.value) startQr()
  } catch (e) { message.value = e.message }
}

async function startQr() {
  scanning.value = true
  const ctrl = new AbortController()
  setTimeout(() => ctrl.abort(), 20000)
  try { qrUrl.value = (await scanQr(video.value, { signal: ctrl.signal })) || '' } catch (e) { message.value = e.message } finally { scanning.value = false }
  if (nfcUrl.value && qrUrl.value) submit()
}

async function submit() {
  const { data } = await adminApi.qc(card.value.token, { nfc_url: nfcUrl.value, qr_url: qrUrl.value, revoke_on_fail: revoke.value })
  if (data.passed) {
    state.value = 'ok'
    message.value = `Kartu #${card.value.sequence} lolos QC`
    beep(true)
    setTimeout(() => next(), 1200)
  } else {
    state.value = 'fail'
    message.value = data.reason
    beep(false)
  }
}
</script>

<template>
  <PageHeader eyebrow="Produksi" title="QC">
    <AppSelect v-if="batches?.length" v-model="batch" :options="batches.map((b) => ({ value: b.id, label: `${b.code} · ${b.quantity} kartu` }))" class="!w-auto" aria-label="Batch" @change="next()" />
  </PageHeader>

  <StationScreen :state="state === 'ok' ? 'ok' : state === 'fail' ? 'fail' : 'idle'">
    <p v-if="state === 'done'" class="font-display text-2xl">Tidak ada kartu yang menunggu QC</p>
    <div v-else-if="card" class="grid w-full max-w-xl justify-items-center gap-4">
      <span class="label !text-current opacity-80">Periksa · sisa {{ card.remaining }}</span>
      <span class="num text-[64px] leading-none">#{{ card.sequence }}</span>
      <p class="font-display text-2xl">{{ message || card.short_code }}</p>
      <div class="flex w-full gap-2">
        <AppButton v-if="canNfc" @click="readNfc">1 · Tap NFC</AppButton>
        <AppInput v-model="nfcUrl" :placeholder="`URL NFC (${config.resolverUrl}/c/…)`" aria-label="URL dari NFC" />
      </div>
      <div class="flex w-full gap-2">
        <AppButton v-if="canQr" :disabled="scanning" @click="startQr">2 · Scan QR</AppButton>
        <AppInput v-model="qrUrl" placeholder="URL dari QR" aria-label="URL dari QR" />
      </div>
      <video v-show="scanning" ref="video" class="w-full max-w-[360px] rounded-md bg-surface-2" muted playsinline></video>
      <AppCheckbox v-model="revoke">Cabut kartu jika gagal (cetak ulang)</AppCheckbox>
      <div class="flex flex-wrap justify-center gap-2">
        <AppButton size="lg" :disabled="!nfcUrl || !qrUrl" @click="submit">Periksa</AppButton>
        <AppButton variant="secondary" size="lg" @click="next(card.sequence)">Lewati</AppButton>
      </div>
    </div>
  </StationScreen>
</template>
