<script setup>
// Aktivasi di tempat (penjualan langsung / door to door): tempel atau ketik kartu → isi data pelanggan + kode aktivasi
// yang tercetak di kartu → kartu aktif, akun pelanggan dibuat, kode akun 6 angka ditampilkan SEKALI untuk diserahkan.
// Pelanggan lalu masuk dengan menempelkan kartu + kode akun, dan bisa menghubungkan Google / mengganti kode sendiri.
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { CircleAlert, CreditCard, MessageCircle, Nfc, RotateCcw, Search, ShieldCheck } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { config } from '@/config'
import { nfcErrorMessage, nfcSupported, readOnce } from '@/lib/nfc'
import { rupiah } from '@/lib/format'

const canNfc = nfcSupported()
const lookup = ref('')
const card = ref(null)
const scanning = ref(false)
const busy = ref(false)
const error = ref('')
const errors = ref({})
const result = ref(null)
const blank = () => ({ name: '', email: '', whatsapp: '', title: '', company: '', activation_code: '', amount: null, payment: 'unpaid', note: '' })
const form = reactive(blank())
let ctrl = null

const PAYMENT = [{ value: 'unpaid', label: 'Belum dibayar' }, { value: 'qris', label: 'Lunas · QRIS' }, { value: 'manual', label: 'Lunas · manual / tunai' }]

async function find(q = lookup.value) {
  error.value = ''
  card.value = null
  if (!q.trim()) return (error.value = 'Tempelkan kartu, atau ketik kode pendek yang tercetak di kartu.')
  busy.value = true
  try {
    card.value = (await adminApi.fieldLookup(q.trim())).data
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function scan() {
  error.value = ''
  ctrl = new AbortController()
  scanning.value = true
  try {
    const { url } = await readOnce({ signal: ctrl.signal })
    if (!url) throw new Error('Kartu tidak berisi alamat TapCard.')
    lookup.value = ''
    await find(url)
  } catch (e) {
    if (e?.name !== 'AbortError') error.value = nfcErrorMessage(e)
  } finally {
    scanning.value = false
    ctrl?.abort()
    ctrl = null
  }
}
onBeforeUnmount(() => ctrl?.abort())

async function submit() {
  errors.value = {}
  error.value = ''
  busy.value = true
  try {
    const paid = form.payment !== 'unpaid'
    const res = await adminApi.fieldActivate({
      token: card.value.token, activation_code: form.activation_code,
      name: form.name, email: form.email, whatsapp: form.whatsapp || null, title: form.title || null, company: form.company || null,
      amount: form.amount || 0, paid, payment_method: paid ? form.payment : null, note: form.note || null,
    })
    result.value = res.data
  } catch (e) {
    errors.value = e.fields ?? {}
    if (!e.fields) error.value = e.message
  } finally {
    busy.value = false
  }
}

function next() {
  Object.assign(form, blank())
  result.value = null
  card.value = null
  lookup.value = ''
  errors.value = {}
  error.value = ''
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
  <PageHeader eyebrow="Penjualan langsung" title="Aktivasi di tempat" />

  <!-- 3. Selesai: kode akun ditampilkan sekali -->
  <AppPanel v-if="result" title="Kartu aktif">
    <div class="grid gap-5">
      <div class="grid gap-2 rounded-md border border-line-strong bg-surface-2 p-5 text-center">
        <span class="label">Kode akun untuk {{ result.customer.name }}</span>
        <span class="font-mono text-[40px] leading-none font-semibold tracking-[.25em]">{{ result.account_code }}</span>
        <span class="text-sm text-muted">Tunjukkan atau kirim ke pelanggan sekarang — kode ini tidak bisa ditampilkan lagi.</span>
      </div>
      <dl class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
        <dt class="text-muted">Kartu</dt><dd class="font-mono">{{ result.card.short_code }}</dd>
        <dt class="text-muted">Akun</dt><dd>{{ result.customer.email }} <AppBadge :tone="result.customer.new_account ? 'ok' : 'info'">{{ result.customer.new_account ? 'Akun baru' : 'Akun lama' }}</AppBadge></dd>
        <dt class="text-muted">Pembayaran</dt><dd>{{ rupiah(result.sale.amount) }} · {{ result.sale.status === 'paid' ? `Lunas (${result.sale.payment_label})` : 'Belum dibayar' }}</dd>
      </dl>
      <ol class="grid list-decimal gap-1 pl-5 text-sm text-muted">
        <li>Pelanggan menempelkan kartu ke HP-nya (Android atau iPhone).</li>
        <li>Di halaman kartu, ketuk <b class="text-fg">Masuk sebagai pemilik</b>, lalu ketik kode akun.</li>
        <li>Setelah masuk: ubah nama & tampilan kartu, ganti kode akun, dan hubungkan Google di Pengaturan.</li>
      </ol>
      <div class="flex flex-wrap gap-2">
        <AppButton v-if="waUrl" :href="waUrl" target="_blank" rel="noopener"><MessageCircle class="size-5" /> Kirim ke WhatsApp pelanggan</AppButton>
        <AppButton variant="secondary" :to="`/cards/${result.card.token}`"><CreditCard class="size-5" /> Detail kartu</AppButton>
        <AppButton variant="secondary" @click="next"><RotateCcw class="size-5" /> Aktivasi kartu berikutnya</AppButton>
      </div>
    </div>
  </AppPanel>

  <template v-else>
    <!-- 1. Kartu -->
    <AppPanel title="1. Kartu">
      <div class="grid gap-4">
        <AppButton v-if="canNfc && !scanning" size="lg" block :disabled="busy" @click="scan"><Nfc class="size-5" /> Tempelkan kartu</AppButton>
        <div v-if="scanning" class="grid justify-items-center gap-2 rounded-md border border-line-strong bg-surface-2 p-5 text-center" aria-live="polite">
          <Nfc class="size-9 animate-pulse text-info" aria-hidden="true" />
          <p class="font-medium">Tempelkan kartu ke bagian belakang HP ini</p>
          <AppButton variant="ghost" @click="ctrl?.abort()">Batal</AppButton>
        </div>
        <form class="flex gap-2" @submit.prevent="find()">
          <AppInput v-model="lookup" :placeholder="canNfc ? 'atau ketik kode pendek, mis. 7K2M-9QXP' : 'Kode pendek di kartu, mis. 7K2M-9QXP'" autocapitalize="characters" aria-label="Kode pendek kartu" />
          <AppButton type="submit" variant="secondary" :loading="busy && !card"><Search class="size-5" /> Cari</AppButton>
        </form>
        <p v-if="error && !card" class="flex gap-2 text-sm text-danger" role="alert"><CircleAlert class="size-5 flex-none" /> {{ error }}</p>
        <div v-if="card" class="flex flex-wrap items-center gap-3 rounded-md border border-line bg-surface-2 px-4 py-3">
          <CreditCard class="size-5 text-muted" aria-hidden="true" />
          <span class="font-mono">{{ card.short_code }}</span>
          <AppBadge :tone="card.ready ? 'ok' : 'danger'">{{ card.ready ? 'Siap diaktifkan' : card.status_label }}</AppBadge>
          <span v-if="card.reason" class="text-sm text-danger">{{ card.reason }}</span>
          <span v-if="card.locked_until" class="text-sm text-danger">Kode aktivasi terkunci sementara karena salah berulang kali.</span>
        </div>
      </div>
    </AppPanel>

    <!-- 2. Pelanggan & pembayaran -->
    <AppPanel v-if="card?.ready" title="2. Pelanggan & pembayaran">
      <form class="grid gap-4" novalidate @submit.prevent="submit">
        <div class="grid gap-4 sm:grid-cols-2">
          <AppField label="Nama *" :error="errors.name?.[0]"><AppInput v-model.trim="form.name" maxlength="120" autocomplete="off" :invalid="!!errors.name" /></AppField>
          <AppField label="Email *" hint="Untuk akun & menghubungkan Google nanti." :error="errors.email?.[0]">
            <AppInput v-model.trim="form.email" type="email" inputmode="email" maxlength="190" autocomplete="off" :invalid="!!errors.email" />
          </AppField>
          <AppField label="WhatsApp" hint="Tampil di bio kartu & untuk kirim kode akun."><AppInput v-model.trim="form.whatsapp" inputmode="tel" maxlength="32" /></AppField>
          <AppField label="Jabatan"><AppInput v-model.trim="form.title" maxlength="120" /></AppField>
          <AppField label="Perusahaan / usaha"><AppInput v-model.trim="form.company" maxlength="120" /></AppField>
          <AppField label="Kode aktivasi *" hint="Tercetak di kartu / kemasan." :error="errors.activation_code?.[0]">
            <AppInput v-model="form.activation_code" code maxlength="9" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXX-XXXX" :invalid="!!errors.activation_code" />
          </AppField>
        </div>
        <div class="grid gap-4 border-t border-line pt-4 sm:grid-cols-2">
          <AppField label="Harga kartu (Rp)" :error="errors.amount?.[0]"><AppInput v-model.number="form.amount" type="number" min="0" inputmode="numeric" placeholder="0" /></AppField>
          <AppField label="Pembayaran" :error="errors.payment_method?.[0]"><AppSegmented v-model="form.payment" :options="PAYMENT" label="Pembayaran" /></AppField>
          <AppField label="Catatan (opsional)" class="sm:col-span-2"><AppInput v-model="form.note" maxlength="200" placeholder="mis. alamat / acara" /></AppField>
        </div>
        <p class="flex gap-2 text-sm text-muted"><ShieldCheck class="size-5 flex-none text-info" aria-hidden="true" />
          Sistem membuat kode akun 6 angka acak. Anda tidak bisa login sebagai pelanggan; semua aktivasi tercatat di audit atas nama Anda.</p>
        <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>
        <div><AppButton type="submit" size="lg" :loading="busy">Aktifkan kartu</AppButton></div>
      </form>
    </AppPanel>
  </template>
</template>
