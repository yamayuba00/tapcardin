<script setup>
// M-03 / AFD F-04 — per kartu: tulis → verifikasi → kunci → lapor UID. Layar penuh hijau/merah + suara.
import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppCheckbox from '@/components/ui/AppCheckbox/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import StationScreen from '@/components/common/StationScreen/index.vue'
import { adminApi } from '@/api/admin'
import { useAsync } from '@/composables/useAsync'
import { beep } from '@/lib/feedback'
import { lock, nfcSupported, readOnce, writeUrl } from '@/lib/nfc'

const route = useRoute()
const supported = nfcSupported()
const batch = ref(route.query.batch || '')
const card = ref(null)
const state = ref('idle') // idle | waiting | ok | fail | done
const message = ref('')
const lockAfter = ref(true)
const manualUid = ref('')
let abort

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
  message.value = 'Tempel kartu…'
  abort?.abort()
  abort = new AbortController()
  try {
    await writeUrl(card.value.url)
    message.value = 'Memverifikasi… tahan kartu'
    const read = await readOnce({ signal: abort.signal })
    if (read.url !== card.value.url) throw new Error('URL di chip tidak cocok. Ulangi tulis sebelum dikunci.')
    if (lockAfter.value) await lock()
    abort.abort()
    await report(read.serialNumber)
  } catch (e) {
    abort?.abort()
    state.value = 'fail'
    message.value = e.message || 'Gagal menulis kartu'
    beep(false)
  }
}

async function manual() {
  try { await report(manualUid.value) } catch (e) { state.value = 'fail'; message.value = e.message; beep(false) }
}
</script>

<template>
  <PageHeader eyebrow="Produksi" title="Stasiun encode">
    <AppSelect v-if="batches?.length" v-model="batch" :options="batches.map((b) => ({ value: b.id, label: `${b.code} · ${b.quantity} kartu · ${b.chip.toUpperCase()}` }))" class="!w-auto" aria-label="Batch" />
  </PageHeader>

  <p v-if="!supported" class="rounded-md border border-line-strong bg-surface-2 px-4 py-3 text-sm">
    Web NFC hanya tersedia di Chrome Android lewat HTTPS. Di perangkat ini gunakan input UID manual (untuk uji) atau buka halaman ini dari ponsel Android.
  </p>
  <p v-if="batches && !batches.length" class="text-muted">Belum ada batch yang siap di-encode. Buat batch dan generate token dulu.</p>

  <StationScreen :state="state === 'ok' ? 'ok' : state === 'fail' ? 'fail' : 'idle'">
    <p v-if="state === 'done'" class="font-display text-2xl">Semua kartu sudah di-encode</p>
    <div v-else-if="card" class="grid justify-items-center gap-4">
      <span class="label !text-current opacity-80">Urutan · sisa {{ card.remaining }} dari {{ card.total }}</span>
      <span class="num text-[64px] leading-none">#{{ card.sequence }}</span>
      <p class="font-display text-2xl">{{ message || card.holder || 'Stok' }}</p>
      <p class="font-mono text-sm opacity-80">{{ card.short_code }} · {{ card.url }}</p>
      <div class="flex flex-wrap justify-center gap-2">
        <AppButton v-if="supported" size="lg" :disabled="state === 'waiting'" @click="encode">{{ state === 'fail' ? 'Ulangi' : 'Tulis' }}</AppButton>
        <AppButton variant="secondary" size="lg" @click="next(card.sequence)">Lewati</AppButton>
      </div>
      <AppCheckbox v-if="supported" v-model="lockAfter">Kunci chip permanen setelah verifikasi</AppCheckbox>
      <form v-else class="flex flex-wrap justify-center gap-2" @submit.prevent="manual">
        <AppInput v-model="manualUid" placeholder="UID chip, mis. 04A1B2C3D4E5F6" class="max-w-xs" />
        <AppButton type="submit" variant="secondary" :disabled="manualUid.length < 8">Catat</AppButton>
      </form>
    </div>
  </StationScreen>
</template>
