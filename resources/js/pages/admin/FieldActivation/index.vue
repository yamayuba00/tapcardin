<script setup>
// Jual kartu (door-to-door / penjualan langsung) — satu layar, ramah ponsel:
//   1. Tempel kartu: kartu POLOS (batch desain seragam, belum di-encode) langsung ditulis dari stok; kartu yang sudah
//      di-encode & lolos QC dipakai apa adanya. Tanpa NFC: ketik nomor kartu, atau datang dari tombol Aktivasi di
//      halaman batch (?card=).
//   2. Isi pelanggan (nama, WhatsApp, email) — harga & cara bayar terakhir diingat di perangkat ini.
//   3. Aktif → kode akun 6 angka → kirim ke WhatsApp → "Jual kartu berikutnya" langsung siap ditempel.
// Tanpa kode aktivasi: staf memegang kartunya & setiap penjualan tercatat di audit atas namanya.
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ChevronDown, CircleAlert, CreditCard, MessageCircle, Nfc, RotateCcw, Search } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { config } from '@/config'
import { beep } from '@/lib/feedback'
import { cardTokenFromUrl, encodeUrl, nfcErrorMessage, nfcSupported, readOnce } from '@/lib/nfc'
import { rupiah } from '@/lib/format'

const route = useRoute()
const router = useRouter()
const canNfc = nfcSupported()

// harga & cara bayar terakhir diingat di perangkat ini (penjual door-to-door menjual kartu yang sama)
const PREF = 'tc-sell-defaults'
const prefs = (() => { try { return JSON.parse(localStorage.getItem(PREF) || '{}') } catch { return {} } })()
const blank = () => ({ name: '', whatsapp: '', email: '', title: '', company: '', amount: prefs.amount ?? null, payment: prefs.payment ?? 'unpaid', note: '' })
const form = reactive(blank())
function savePrefs() {
  prefs.amount = form.amount
  prefs.payment = form.payment
  try { localStorage.setItem(PREF, JSON.stringify(prefs)) } catch { /* abaikan */ }
}

const more = ref(false)
const summary = ref(null)
const lookup = ref('')
const card = ref(null)
const busy = ref(false)
const nfcState = ref('idle') // idle | waiting | writing
const nfcMessage = ref('')
const error = ref('')
const errors = ref({})
const result = ref(null)
let ctrl = null

const PAYMENT = [{ value: 'unpaid', label: 'Belum bayar' }, { value: 'qris', label: 'QRIS' }, { value: 'manual', label: 'Tunai' }]

async function loadSummary() {
  try { summary.value = (await adminApi.fieldSummary()).data } catch { /* ringkasan opsional */ }
}
onMounted(() => {
  loadSummary()
  if (route.query.card) find(String(route.query.card))
})
onBeforeUnmount(() => ctrl?.abort())

async function find(q = lookup.value) {
  error.value = ''
  card.value = null
  if (!q.trim()) return (error.value = 'Ketik nomor kartu yang tercetak, mis. 7K2M-9QXP.')
  busy.value = true
  try {
    card.value = (await adminApi.fieldLookup(q.trim())).data
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

/** Tempel kartu: kartu TapCard → cari; kartu polos → tulis kartu berikutnya dari stok batch desain seragam. */
async function tap() {
  error.value = ''
  card.value = null
  ctrl?.abort()
  ctrl = new AbortController()
  nfcState.value = 'waiting'
  nfcMessage.value = 'Tempelkan kartu ke bagian belakang HP'
  try {
    const { url } = await readOnce({ signal: ctrl.signal })
    ctrl.abort()
    if (url && cardTokenFromUrl(url)) {
      nfcState.value = 'idle'
      await find(url)
      beep(card.value?.ready === true)
      return
    }
    // kartu polos: ambil token stok lalu tulis chip (kartu tetap ditempel)
    nfcState.value = 'writing'
    const stock = (await adminApi.fieldBlank()).data
    const uid = await encodeUrl(stock.url, {
      onStatus: (t) => (nfcMessage.value = t),
      onRetry: () => beep(false),
      register: (c) => (ctrl = c),
    })
    await adminApi.fieldEncoded(stock.token, uid)
    nfcState.value = 'idle'
    await find(stock.token)
    beep(true)
    loadSummary()
  } catch (e) {
    nfcState.value = 'idle'
    if (e?.name !== 'AbortError') {
      error.value = e?.name && e.name !== 'Error' ? nfcErrorMessage(e) : e.message
      beep(false)
    }
  }
}
function cancelTap() {
  ctrl?.abort()
  nfcState.value = 'idle'
}

async function submit() {
  errors.value = {}
  error.value = ''
  busy.value = true
  try {
    const paid = form.payment !== 'unpaid'
    const res = await adminApi.fieldActivate({
      token: card.value.token,
      name: form.name, email: form.email, whatsapp: form.whatsapp || null, title: form.title || null, company: form.company || null,
      amount: form.amount || 0, paid, payment_method: paid ? form.payment : null, note: form.note || null,
    })
    savePrefs()
    result.value = res.data
    loadSummary()
  } catch (e) {
    errors.value = e.fields ?? {}
    if (!e.fields) error.value = e.message
  } finally {
    busy.value = false
  }
}

/** Kartu berikutnya: kosongkan data pelanggan (harga & cara bayar tetap), lalu langsung siap ditempel. */
function next() {
  Object.assign(form, blank())
  result.value = null
  card.value = null
  lookup.value = ''
  errors.value = {}
  error.value = ''
  more.value = false
  if (route.query.card) router.replace({ query: {} })
  if (canNfc) tap()
}

/** wa.me butuh nomor internasional tanpa + (08xx → 628xx). */
const waDigits = (raw) => {
  const d = String(raw || '').replace(/\D/g, '')
  return d.startsWith('0') ? '62' + d.slice(1) : d
}
const waUrl = computed(() => {
  if (!result.value || !form.whatsapp) return null
  const r = result.value
  const text = [
    `Halo ${r.customer.name}, kartu TapCard Anda (${r.card.short_code}) sudah aktif.`,
    '',
    'Cara masuk ke akun:',
    '1. Tempelkan kartu ke bagian belakang HP.',
    '2. Di halaman yang terbuka, ketuk "Masuk sebagai pemilik".',
    `3. Ketik kode akun: ${r.account_code}`,
    '',
    `Setelah masuk, atur nama & tampilan kartu, ganti kode akun di menu Kartu, dan hubungkan Google di Pengaturan. ${config.appUrl}`,
  ].join('\n')
  return `https://wa.me/${waDigits(form.whatsapp)}?text=${encodeURIComponent(text)}`
})
</script>

<template>
  <PageHeader eyebrow="Penjualan langsung" title="Jual kartu" />

  <!-- ringkasan hari ini + stok -->
  <ul v-if="summary" class="grid grid-cols-3 gap-2 sm:max-w-xl" aria-label="Ringkasan hari ini">
    <li class="grid min-w-0 gap-0.5 rounded-md border border-line bg-surface p-3"><span class="label !text-[10px]">Terjual hari ini</span><span class="text-xl font-semibold">{{ summary.today.count }}</span></li>
    <li class="grid min-w-0 gap-0.5 rounded-md border border-line bg-surface p-3"><span class="label !text-[10px]">Pendapatan</span><span class="truncate text-xl font-semibold">{{ rupiah(summary.today.total) }}</span></li>
    <li class="grid min-w-0 gap-0.5 rounded-md border border-line bg-surface p-3"><span class="label !text-[10px]">Stok siap</span><span class="text-xl font-semibold">{{ summary.stock.ready + summary.stock.blank }}</span></li>
  </ul>
  <p v-if="summary && summary.today.unpaid" class="-mt-2 text-sm text-warn">{{ summary.today.unpaid }} penjualan hari ini belum dibayar — tandai lunas di detail kartu.</p>

  <!-- 3. Selesai -->
  <AppPanel v-if="result" title="Kartu aktif ✓">
    <div class="grid gap-5">
      <div class="grid gap-2 rounded-md border border-line-strong bg-surface-2 p-5 text-center">
        <span class="label">Kode akun untuk {{ result.customer.name }}</span>
        <span class="font-mono text-[40px] leading-none font-semibold tracking-[.25em]">{{ result.account_code }}</span>
        <span class="text-sm text-muted">Kirim ke pelanggan sekarang — kode ini tidak bisa ditampilkan lagi.</span>
      </div>
      <dl class="grid grid-cols-[max-content_minmax(0,1fr)] gap-x-6 gap-y-2 text-sm">
        <dt class="text-muted">Kartu</dt><dd class="font-mono">{{ result.card.short_code }}</dd>
        <dt class="text-muted">Akun</dt><dd class="truncate">{{ result.customer.email }} <AppBadge :tone="result.customer.new_account ? 'ok' : 'info'">{{ result.customer.new_account ? 'Baru' : 'Lama' }}</AppBadge></dd>
        <dt class="text-muted">Bayar</dt><dd>{{ rupiah(result.sale.amount) }} · {{ result.sale.status === 'paid' ? `Lunas (${result.sale.payment_label})` : 'Belum dibayar' }}</dd>
      </dl>
      <div class="grid gap-2 sm:flex sm:flex-wrap">
        <AppButton v-if="waUrl" size="lg" :href="waUrl" target="_blank" rel="noopener"><MessageCircle class="size-5" /> Kirim ke WhatsApp</AppButton>
        <AppButton size="lg" :variant="waUrl ? 'secondary' : 'primary'" @click="next"><RotateCcw class="size-5" /> Jual kartu berikutnya</AppButton>
        <AppButton variant="ghost" :to="`/cards/${result.card.token}`"><CreditCard class="size-5" /> Detail kartu</AppButton>
      </div>
    </div>
  </AppPanel>

  <template v-else>
    <!-- 1. Kartu -->
    <AppPanel title="1 · Kartu">
      <div class="grid gap-4">
        <template v-if="canNfc">
          <div v-if="nfcState !== 'idle'" class="grid justify-items-center gap-2 rounded-md border border-line-strong bg-surface-2 p-6 text-center" aria-live="polite">
            <Nfc class="size-10 animate-pulse text-info" aria-hidden="true" />
            <p class="font-semibold">{{ nfcState === 'writing' ? 'Kartu polos — menyiapkan kartu' : 'Tempelkan kartu' }}</p>
            <p class="text-sm text-muted">{{ nfcMessage }}</p>
            <AppButton variant="ghost" @click="cancelTap">Batal</AppButton>
          </div>
          <AppButton v-else size="lg" block :disabled="busy" @click="tap"><Nfc class="size-5" /> Tempel kartu</AppButton>
          <p v-if="nfcState === 'idle' && !card" class="text-center text-sm text-muted">Kartu polos dari stok desain seragam langsung disiapkan saat ditempel.</p>
        </template>
        <form class="flex gap-2" @submit.prevent="find()">
          <AppInput v-model="lookup" :placeholder="canNfc ? 'atau ketik nomor kartu' : 'Nomor kartu, mis. 7K2M-9QXP'" autocapitalize="characters" aria-label="Nomor kartu (kode pendek)" />
          <AppButton type="submit" variant="secondary" :loading="busy && !card"><Search class="size-5" /> Cari</AppButton>
        </form>
        <p v-if="error && !card" class="flex gap-2 text-sm text-danger" role="alert"><CircleAlert class="size-5 flex-none" /> {{ error }}</p>
        <p v-if="!canNfc && !card" class="text-sm text-muted">Menempel kartu hanya bisa di Chrome Android dengan NFC menyala. Di perangkat ini ketik nomor kartunya, atau pilih kartu dari halaman batch.</p>
        <div v-if="card" class="flex flex-wrap items-center gap-3 rounded-md border border-line bg-surface-2 px-4 py-3">
          <CreditCard class="size-5 text-muted" aria-hidden="true" />
          <span class="font-mono">{{ card.short_code }}</span>
          <AppBadge :tone="card.ready ? 'ok' : 'danger'">{{ card.ready ? 'Siap dijual' : card.status_label }}</AppBadge>
          <span v-if="card.batch" class="text-sm text-muted">Batch {{ card.batch.code }} #{{ card.batch.sequence }}</span>
          <span v-if="card.reason" class="text-sm text-danger">{{ card.reason }}</span>
          <span v-if="card.ready && card.reserved_for" class="text-sm text-warn">Dipesan oleh {{ card.reserved_for }} — isi email pemesan ini.</span>
        </div>
      </div>
    </AppPanel>

    <!-- 2. Pelanggan & pembayaran -->
    <AppPanel v-if="card?.ready" title="2 · Pelanggan">
      <form class="grid gap-4" novalidate @submit.prevent="submit">
        <div class="grid gap-4 sm:grid-cols-2">
          <AppField label="Nama *" :error="errors.name?.[0]"><AppInput v-model.trim="form.name" maxlength="120" autocomplete="off" autocapitalize="words" :invalid="!!errors.name" /></AppField>
          <AppField label="WhatsApp" hint="Untuk kirim kode akun & tampil di bio." :error="errors.whatsapp?.[0]"><AppInput v-model.trim="form.whatsapp" type="tel" inputmode="tel" maxlength="32" /></AppField>
          <AppField label="Email *" hint="Untuk akun & masuk dengan Google nanti." :error="errors.email?.[0]" class="sm:col-span-2">
            <AppInput v-model.trim="form.email" type="email" inputmode="email" maxlength="190" autocomplete="off" autocapitalize="off" :invalid="!!errors.email" />
          </AppField>
        </div>
        <button type="button" class="inline-flex min-h-11 cursor-pointer items-center gap-2 justify-self-start text-sm font-semibold text-accent-text" :aria-expanded="more" @click="more = !more">
          <ChevronDown class="size-4 transition-transform" :class="more ? 'rotate-180' : ''" aria-hidden="true" /> Jabatan, usaha & catatan (opsional)
        </button>
        <div v-if="more" class="grid gap-4 sm:grid-cols-2">
          <AppField label="Jabatan"><AppInput v-model.trim="form.title" maxlength="120" /></AppField>
          <AppField label="Perusahaan / usaha"><AppInput v-model.trim="form.company" maxlength="120" /></AppField>
          <AppField label="Catatan" class="sm:col-span-2"><AppInput v-model="form.note" maxlength="200" placeholder="mis. alamat / acara" /></AppField>
        </div>
        <div class="grid gap-4 border-t border-line pt-4 sm:grid-cols-2">
          <AppField label="Harga (Rp)" hint="Diingat untuk penjualan berikutnya." :error="errors.amount?.[0]"><AppInput v-model.number="form.amount" type="number" min="0" inputmode="numeric" placeholder="0" /></AppField>
          <AppField label="Pembayaran" :error="errors.payment_method?.[0]"><AppSegmented v-model="form.payment" :options="PAYMENT" label="Pembayaran" /></AppField>
        </div>
        <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>
        <AppButton type="submit" size="lg" block :loading="busy">Aktifkan & buat akun</AppButton>
        <p class="text-center text-xs text-muted">Kode aktivasi tidak diperlukan. Penjualan tercatat atas nama Anda.</p>
      </form>
    </AppPanel>
  </template>
</template>
