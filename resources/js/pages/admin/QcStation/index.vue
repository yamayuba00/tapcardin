<script setup>
// M-04 / PR-04 — Stasiun QC: setiap kartu dicek dua jalur masuknya — URL di chip NFC dan URL di QR cetak harus sama-sama
// menunjuk token kartu yang sedang diperiksa. Lolos → kartu siap dikirim; gagal → catat alasan (opsional: cabut & cetak ulang).
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Check, CircleAlert, Nfc, QrCode, ScanLine } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppCheckbox from '@/components/ui/AppCheckbox/index.vue'
import AppEmpty from '@/components/ui/AppEmpty/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import StationScreen from '@/components/common/StationScreen/index.vue'
import { adminApi } from '@/api/admin'
import { config } from '@/config'
import { useAsync } from '@/composables/useAsync'
import { beep } from '@/lib/feedback'
import { nfcSupported, readOnce } from '@/lib/nfc'
import { qrSupported, scanQr } from '@/lib/scanner'

const route = useRoute()
const router = useRouter()
const canNfc = nfcSupported()
const canQr = ref(false)
const batch = ref(route.query.batch || '')
const card = ref(null)
const nfcUrl = ref('')
const qrUrl = ref('')
const state = ref('idle') // idle | ok | fail | done
const message = ref('')
const revoke = ref(false)
const plain = ref(false) // kartu polos tanpa QR tercetak: cukup periksa URL di chip NFC
const video = ref(null)
const scanning = ref(false)
let abort

const { data: batches } = useAsync(async () => {
  const res = await adminApi.stationBatches()
  if (!batch.value && res.data[0]) batch.value = res.data[0].id
  return res
})
const current = computed(() => batches.value?.find((b) => b.id === batch.value))
// ganti batch → muat kartu berikutnya dari batch itu (AppSelect tidak memancarkan `change`)
watch(batch, (v) => {
  router.replace({ query: { ...route.query, batch: v || undefined } })
  next()
}, { immediate: true })
onMounted(async () => (canQr.value = await qrSupported().catch(() => false)))

async function next(after = 0) {
  state.value = 'idle'
  message.value = ''
  nfcUrl.value = ''
  qrUrl.value = ''
  card.value = null
  if (!batch.value) return
  card.value = (await adminApi.nextCard(batch.value, 'qc', after || null)).data
  if (!card.value) state.value = 'done'
}

async function readNfc() {
  abort?.abort()
  abort = new AbortController()
  message.value = 'Tempelkan kartu ke bagian belakang ponsel…'
  try {
    nfcUrl.value = (await readOnce({ signal: abort.signal })).url || ''
    abort.abort()
    if (plain.value) {
      submit()
      return
    }
    message.value = 'Chip terbaca. Lanjut scan QR di kartu.'
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
  const { data } = await adminApi.qc(card.value.token, {
    nfc_url: nfcUrl.value, qr_url: plain.value ? null : qrUrl.value, revoke_on_fail: revoke.value, without_qr: plain.value,
  })
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
  <PageHeader eyebrow="Produksi" title="Stasiun QC (quality control)">
    <AppSelect
      v-if="batches?.length" v-model="batch" class="!w-auto min-w-64" aria-label="Pilih batch"
      :options="batches.map((b) => ({ value: b.id, label: `${b.code} · ${b.quantity} kartu`, description: b.status }))"
    />
  </PageHeader>
  <p class="max-w-3xl text-muted">
    Pastikan setiap kartu bekerja sebelum dikirim: <b>chip NFC</b> dan <b>QR tercetak</b> harus sama-sama membuka kartu yang benar.
    Kartu yang lolos otomatis siap dikirim; yang gagal dicatat beserta alasannya.
  </p>

  <AppSkeleton v-if="!batches" class="!h-80" />
  <AppEmpty v-else-if="!batches.length" text="Belum ada batch untuk diperiksa. Batch muncul di sini setelah token dibuat dan kartu dicetak / di-encode.">
    <AppButton to="/batches">Buka daftar batch</AppButton>
  </AppEmpty>

  <template v-else>
    <p v-if="!canNfc || !canQr" class="flex items-start gap-3 rounded-md border border-line-strong bg-surface-2 px-4 py-3 text-sm">
      <CircleAlert class="mt-0.5 size-5 flex-none text-warn" />
      <span>
        <template v-if="!canNfc">Baca NFC hanya tersedia di Chrome Android (HTTPS). </template>
        <template v-if="!canQr">Kamera untuk scan QR tidak tersedia di perangkat ini. </template>
        Anda tetap bisa menempelkan URL hasil pembacaan secara manual di kolom langkah 1 & 2.
      </span>
    </p>

    <StationScreen :state="state === 'ok' ? 'ok' : state === 'fail' ? 'fail' : 'idle'">
      <div v-if="state === 'done'" class="grid justify-items-center gap-3">
        <Check class="size-12 text-ok" aria-hidden="true" />
        <p class="font-display text-2xl">Semua kartu di batch {{ current?.code }} sudah diperiksa</p>
        <p class="text-muted">Pilih batch lain di kanan atas, atau lanjutkan pengiriman dari halaman batch.</p>
        <AppButton variant="secondary" to="/batches">Buka daftar batch</AppButton>
      </div>
      <AppSkeleton v-else-if="!card" class="!h-64 w-full max-w-xl" />
      <div v-else class="grid w-full max-w-xl justify-items-center gap-5">
        <div class="grid justify-items-center gap-1">
          <span class="label !text-current opacity-80">Batch {{ current?.code }} · sisa {{ card.remaining }} kartu</span>
          <span class="num text-[64px] leading-none">#{{ card.sequence }}</span>
          <span class="font-mono text-sm opacity-80">{{ card.short_code }}</span>
        </div>
        <p v-if="message" class="font-display text-xl" role="status">{{ message }}</p>

        <ol class="grid w-full gap-3 text-left">
          <li class="grid gap-2 rounded-md border border-line bg-bg/40 p-3">
            <span class="flex items-center gap-2 text-sm font-semibold">
              <span class="grid size-6 place-items-center rounded-full text-xs" :class="nfcUrl ? 'bg-solid-ok text-on-solid' : 'border border-line-strong'"><Check v-if="nfcUrl" class="size-3.5" /><template v-else>1</template></span>
              Baca chip NFC
            </span>
            <div class="flex gap-2">
              <AppButton v-if="canNfc" variant="secondary" @click="readNfc"><Nfc class="size-5" /> Tap kartu</AppButton>
              <AppInput v-model="nfcUrl" :placeholder="`${config.resolverUrl}/c/…`" aria-label="URL dari chip NFC" />
            </div>
          </li>
          <li v-if="!plain" class="grid gap-2 rounded-md border border-line bg-bg/40 p-3">
            <span class="flex items-center gap-2 text-sm font-semibold">
              <span class="grid size-6 place-items-center rounded-full text-xs" :class="qrUrl ? 'bg-solid-ok text-on-solid' : 'border border-line-strong'"><Check v-if="qrUrl" class="size-3.5" /><template v-else>2</template></span>
              Scan QR di kartu
            </span>
            <div class="flex gap-2">
              <AppButton v-if="canQr" variant="secondary" :disabled="scanning" @click="startQr"><QrCode class="size-5" /> {{ scanning ? 'Memindai…' : 'Scan' }}</AppButton>
              <AppInput v-model="qrUrl" :placeholder="`${config.resolverUrl}/c/…?src=qr`" aria-label="URL dari QR" />
            </div>
            <video v-show="scanning" ref="video" class="w-full max-w-[360px] justify-self-center rounded-md bg-surface-2" muted playsinline></video>
          </li>
        </ol>

        <AppCheckbox v-model="plain">Kartu polos (tanpa QR tercetak): periksa chip NFC saja</AppCheckbox>
        <AppCheckbox v-model="revoke">Jika gagal, cabut kartu ini (untuk dicetak ulang)</AppCheckbox>
        <div class="flex flex-wrap justify-center gap-2">
          <AppButton size="lg" :disabled="!nfcUrl || (!plain && !qrUrl)" @click="submit"><ScanLine class="size-5" /> 3 · Periksa kartu</AppButton>
          <AppButton variant="secondary" size="lg" @click="next(card.sequence)">Lewati kartu ini</AppButton>
        </div>
      </div>
    </StationScreen>
  </template>
</template>
