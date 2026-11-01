<script setup>
// OR-05 — Paket & tagihan: paket aktif + pemakaian, perbandingan paket bulanan/tahunan, checkout (ringkasan → halaman
// bayar gateway), tagihan terbuka / masa tenggang, batal/lanjutkan langganan, riwayat invoice (server-side).
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowRight, Check, CircleCheck, Lock, QrCode, Receipt, Smartphone, Sparkles, TriangleAlert, X } from 'lucide-vue-next'
import AddonList from '@/components/billing/AddonList/index.vue'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppMeter from '@/components/ui/AppMeter/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { billingApi } from '@/api/workspace'
import { useAction, useAsync } from '@/composables/useAsync'
import { usePagedList } from '@/composables/usePagedList'
import { date, dateTime } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToastStore()
const { data: b, reload } = useAsync(() => billingApi.get())
const invoices = usePagedList(billingApi.invoices, {}, { url: false, sort: '-created_at', perPage: 10 })
const { busy, run } = useAction()
const cycle = ref(route.query.cycle === 'yearly' ? 'yearly' : 'monthly')
const choosing = ref(null) // plan yang sedang di-checkout
const cancelling = ref(false)
const voucherCode = ref('')
const voucher = ref(null) // { code, discount, total } hasil pratinjau
const voucherError = ref('')
const total = computed(() => (voucher.value ? voucher.value.total : price(choosing.value)))
watch([choosing, cycle], () => { voucher.value = null; voucherError.value = '' })
const canManage = computed(() => auth.can('billing.manage'))

const rupiah = (n) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n ?? 0)
const price = (p) => (cycle.value === 'yearly' ? p.price_yearly : p.price_monthly)
const current = computed(() => b.value?.plan)
const sub = computed(() => b.value?.subscription)
const STATUS = { issued: ['warn', 'Belum dibayar'], overdue: ['danger', 'Terlambat'], paid: ['ok', 'Lunas'], void: ['muted', 'Dibatalkan'], draft: ['muted', 'Draf'] }
const SUB = { active: ['ok', 'Aktif'], trialing: ['info', 'Uji coba'], past_due: ['danger', 'Menunggu pembayaran'], cancelled: ['muted', 'Berhenti'], expired: ['muted', 'Berakhir'] }
const gateway = computed(() => b.value?.gateway ?? null) // { code, name, method, simulator } atau null bila belum dikonfigurasi

// tombol "Upgrade" dari layar fitur terkunci membawa ?plan= → langsung buka ringkasan checkout
watch(b, (v) => {
  if (!v || !route.query.plan) return
  const p = v.plans.find((x) => x.key === route.query.plan && !x.free)
  if (p && p.key !== current.value?.key) choosing.value = p
  router.replace({ query: { ...route.query, plan: undefined, cycle: undefined } })
}, { once: true })
if (route.query.paid) {
  toast.ok('Terima kasih! Pembayaran sedang dikonfirmasi — paket aktif begitu gateway memberi kabar.')
  router.replace({ query: {} })
}

function cta(p) {
  if (p.free) return null
  if (p.key === current.value?.key) return sub.value?.status === 'active' ? 'Perpanjang' : 'Aktifkan lagi'
  return `Upgrade ke ${p.name}`
}
async function applyVoucher() {
  voucherError.value = ''
  if (!voucherCode.value.trim()) return
  try {
    voucher.value = (await billingApi.voucher(voucherCode.value.trim(), choosing.value.key, cycle.value)).data
  } catch (e) {
    voucher.value = null
    voucherError.value = e.message
  }
}
async function pay() {
  const res = await run(() => billingApi.checkout(choosing.value.key, cycle.value, voucher.value?.code ?? null)).catch(() => null)
  if (!res) return
  if (!res.data.payment_url) {
    toast.ok(`Voucher diterapkan — paket ${choosing.value.name} langsung aktif.`)
    choosing.value = null
    voucherCode.value = ''
    reload()
    invoices.reload()
    return
  }
  go(res.data.payment_url)
}
async function payOpen() {
  const res = await run(() => billingApi.pay(b.value.open_invoice.id)).catch(() => null)
  if (res) go(res.data.payment_url)
}
/** Simulator ada di app ini (navigasi SPA); gateway asli = halaman luar. */
function go(url) {
  const u = new URL(url, window.location.href)
  if (u.origin === window.location.origin) router.push(u.pathname + u.search)
  else window.location.assign(url)
}
async function toggleCancel(resume) {
  const res = await run(() => billingApi.cancel(resume), resume ? 'Langganan dilanjutkan' : 'Langganan akan berhenti di akhir periode').catch(() => null)
  if (res) { b.value = res.data; cancelling.value = false }
}
const COLUMNS = [
  { key: 'number', label: 'Nomor', sortable: true }, { key: 'plan', label: 'Paket' }, { key: 'total', label: 'Total', sortable: true, align: 'right' },
  { key: 'status', label: 'Status' }, { key: 'created_at', label: 'Tanggal', sortable: true },
]
</script>

<template>
  <PageHeader eyebrow="Workspace" title="Paket & tagihan" />
  <div v-if="!b" class="grid gap-4"><AppSkeleton class="!h-40" /><AppSkeleton class="!h-96" /></div>
  <template v-else>
    <!-- peringatan -->
    <div v-if="sub?.status === 'past_due'" class="grid grid-cols-[auto_minmax(0,1fr)] items-start gap-x-3 gap-y-3 rounded-md border border-danger bg-surface p-4 sm:flex sm:flex-wrap sm:items-center sm:px-4 sm:py-3">
      <TriangleAlert class="mt-0.5 size-5 flex-none text-danger" />
      <span class="min-w-0 flex-1 text-sm">Periode paket sudah berakhir. Fitur {{ sub.plan }} tetap jalan sampai <strong>{{ date(sub.grace_until) }}</strong>; setelah itu workspace kembali ke paket gratis.</span>
      <AppButton v-if="b.open_invoice && canManage" class="col-span-2 w-full sm:w-auto" :loading="busy" @click="payOpen">Bayar sekarang</AppButton>
    </div>
    <div v-else-if="b.open_invoice" class="grid grid-cols-[auto_minmax(0,1fr)] items-start gap-x-3 gap-y-3 rounded-md border border-accent bg-surface p-4 sm:flex sm:flex-wrap sm:items-center sm:px-4 sm:py-3">
      <Receipt class="mt-0.5 size-5 flex-none text-accent-text" />
      <span class="min-w-0 flex-1 text-sm">Tagihan <strong>{{ b.open_invoice.number }}</strong> ({{ rupiah(b.open_invoice.total) }}) belum dibayar — berlaku sampai {{ dateTime(b.open_invoice.due_at) }}.</span>
      <div class="col-span-2 grid grid-cols-2 gap-2 sm:col-span-1 sm:flex">
        <AppButton variant="secondary" :to="`/billing/invoices/${b.open_invoice.id}`">Lihat</AppButton>
        <AppButton v-if="canManage" :loading="busy" @click="payOpen">Lanjutkan pembayaran</AppButton>
      </div>
    </div>

    <!-- paket aktif -->
    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
      <AppPanel title="Paket Anda">
        <div class="grid gap-4">
          <div class="flex flex-wrap items-center gap-3">
            <span class="font-display text-2xl font-bold sm:text-[28px]">{{ current?.name }}</span>
            <AppBadge v-if="sub" :tone="SUB[sub.status][0]">{{ SUB[sub.status][1] }}</AppBadge>
            <AppBadge v-else tone="outline">Gratis selamanya</AppBadge>
          </div>
          <p v-if="sub" class="text-sm text-muted">
            {{ sub.cycle === 'yearly' ? 'Tahunan' : 'Bulanan' }} ·
            <template v-if="sub.cancel_at_period_end">berhenti pada <strong class="text-fg">{{ date(sub.current_period_end) }}</strong></template>
            <template v-else>periode berikutnya mulai <strong class="text-fg">{{ date(sub.current_period_end) }}</strong></template>
          </p>
          <p v-else class="text-sm text-muted">Upgrade kapan saja; kartu, bio link, dan koneksi Anda tetap utuh.</p>
          <div v-if="sub && canManage && ['active', 'trialing'].includes(sub.status)">
            <AppButton v-if="sub.cancel_at_period_end" variant="secondary" :loading="busy" @click="toggleCancel(true)">Lanjutkan langganan</AppButton>
            <AppButton v-else variant="ghost" @click="cancelling = true">Hentikan langganan</AppButton>
          </div>
        </div>
      </AppPanel>
      <AppPanel title="Pemakaian">
        <div class="grid gap-4">
          <AppMeter v-for="u in b.usage" :key="u.label" :label="u.label" :value="u.used" :max="u.limit" />
        </div>
      </AppPanel>
    </div>

    <!-- perbandingan paket -->
    <section class="grid gap-4">
      <div class="grid gap-3 sm:flex sm:flex-wrap sm:items-center sm:justify-between">
        <h2 class="text-xl">Pilih paket</h2>
        <AppSegmented v-model="cycle" label="Siklus tagihan" :options="[{ value: 'monthly', label: 'Bulanan' }, { value: 'yearly', label: 'Tahunan · hemat 2 bulan' }]" class="w-full sm:w-auto" />
      </div>
      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <article
          v-for="p in b.plans" :key="p.key"
          :class="['grid content-start gap-5 rounded-lg border bg-surface p-5 sm:p-6', p.key === current?.key ? 'border-accent' : 'border-line']"
        >
          <div class="flex items-center justify-between gap-2">
            <h3 class="font-display text-xl font-bold">{{ p.name }}</h3>
            <AppBadge v-if="p.key === current?.key" tone="ok">Paket saat ini</AppBadge>
            <span v-else-if="!p.free" class="inline-flex items-center gap-1 text-xs text-accent-text"><Sparkles class="size-4" /> Rekomendasi</span>
          </div>
          <p>
            <span class="font-display text-[34px] leading-none font-bold">{{ p.free ? 'Gratis' : rupiah(price(p)) }}</span>
            <span v-if="!p.free" class="text-muted"> /{{ cycle === 'yearly' ? 'tahun' : 'bulan' }}</span>
            <span v-if="!p.free && cycle === 'yearly' && p.yearly_saving" class="mt-1 block text-sm text-ok">Hemat {{ rupiah(p.yearly_saving) }} per tahun</span>
          </p>
          <ul class="grid gap-2 text-sm">
            <li v-for="c in p.compare" :key="c.label" class="flex items-start gap-2">
              <component :is="c.value === false ? X : Check" :class="['mt-0.5 size-4 flex-none', c.value === false ? 'text-muted' : 'text-ok']" aria-hidden="true" />
              <span :class="c.value === false ? 'text-muted line-through' : ''">{{ c.label }}<template v-if="typeof c.value === 'string'">: <strong>{{ c.value }}</strong></template></span>
            </li>
          </ul>
          <AppButton v-if="cta(p) && canManage" :variant="p.key === current?.key ? 'secondary' : 'primary'" block @click="choosing = p">{{ cta(p) }} <ArrowRight class="size-5" /></AppButton>
        </article>
      </div>
      <p class="flex items-center gap-2 text-sm text-muted"><Lock class="size-4" /> <template v-if="gateway">Pembayaran aman lewat {{ gateway.simulator ? 'gateway simulasi (mode pengembangan)' : gateway.name }}.</template><template v-else>Pembayaran online sedang disiapkan.</template> Harga sudah termasuk pajak.</p>
    </section>

    <AddonList v-if="auth.can('billing.view')" />

    <!-- riwayat invoice -->
    <section class="grid gap-3">
      <h2 class="text-xl">Riwayat tagihan</h2>
      <DataTable
        :columns="COLUMNS" :rows="invoices.rows.value" :meta="invoices.meta.value" :sort="invoices.sort.value" :loading="invoices.loading.value"
        empty-text="Belum ada tagihan." @sort="invoices.toggleSort" @page="invoices.goto" @per-page="invoices.setPerPage"
      >
        <template #cell-number="{ row }"><RouterLink :to="`/billing/invoices/${row.id}`" class="font-mono">{{ row.number }}</RouterLink></template>
        <template #cell-plan="{ row }">{{ row.plan }} · {{ row.cycle === 'yearly' ? 'tahunan' : 'bulanan' }}</template>
        <template #cell-total="{ row }"><span class="font-mono">{{ rupiah(row.total) }}</span></template>
        <template #cell-status="{ row }"><AppBadge :tone="STATUS[row.status][0]">{{ STATUS[row.status][1] }}</AppBadge></template>
        <template #cell-created_at="{ row }"><span class="font-mono text-sm">{{ dateTime(row.issued_at) }}</span></template>
      </DataTable>
    </section>
  </template>

  <!-- ringkasan checkout -->
  <AppModal v-if="choosing" :title="`Checkout ${choosing.name}`" description="Periksa ringkasan, lalu lanjut ke halaman pembayaran aman." @close="choosing = null">
    <AppSegmented v-model="cycle" label="Siklus" :options="[{ value: 'monthly', label: 'Bulanan' }, { value: 'yearly', label: 'Tahunan' }]" />
    <dl class="grid gap-2 rounded-md bg-surface-2 p-4 text-sm">
      <div class="flex justify-between gap-3"><dt>Paket {{ choosing.name }} · {{ cycle === 'yearly' ? '12 bulan' : '1 bulan' }}</dt><dd class="font-mono">{{ rupiah(price(choosing)) }}</dd></div>
      <div v-if="cycle === 'yearly' && choosing.yearly_saving" class="flex justify-between gap-3 text-ok"><dt>Hemat dibanding bulanan</dt><dd class="font-mono">−{{ rupiah(choosing.yearly_saving) }}</dd></div>
      <div v-if="voucher" class="flex justify-between gap-3 text-ok"><dt>Voucher {{ voucher.code }}</dt><dd class="font-mono">−{{ rupiah(voucher.discount) }}</dd></div>
      <div class="flex justify-between gap-3 border-t border-line pt-2 text-base font-semibold"><dt>Total</dt><dd class="font-display text-xl">{{ rupiah(total) }}</dd></div>
    </dl>
    <form class="grid gap-1" @submit.prevent="applyVoucher">
      <label for="voucher-code" class="label">Kode voucher (opsional)</label>
      <div class="flex gap-2">
        <AppInput id="voucher-code" v-model="voucherCode" code maxlength="32" placeholder="mis. LAUNCH25" :invalid="!!voucherError" class="uppercase" />
        <AppButton type="submit" variant="secondary">Pakai</AppButton>
      </div>
      <p v-if="voucherError" class="text-sm text-danger" role="alert">{{ voucherError }}</p>
      <p v-else-if="voucher?.description" class="text-sm text-muted">{{ voucher.description }}</p>
    </form>
    <div class="grid gap-2">
      <span class="label">Metode pembayaran</span>
      <p v-if="gateway" class="flex items-center gap-3 text-sm"><QrCode class="size-5 flex-none text-accent-text" /> {{ gateway.method }}</p>
      <p v-else class="text-sm text-warn">Pembayaran online belum tersedia. Silakan coba lagi nanti atau hubungi kami.</p>
    </div>
    <p class="flex items-start gap-2 text-xs text-muted"><CircleCheck class="mt-0.5 size-4 flex-none text-ok" /> Paket langsung aktif setelah pembayaran terkonfirmasi{{ choosing.key === current?.key ? ' dan periode diperpanjang dari tanggal berakhir saat ini' : '' }}. Tagihan berlaku 24 jam.</p>
    <template #footer>
      <AppButton variant="secondary" @click="choosing = null">Batal</AppButton>
      <AppButton :loading="busy" @click="pay"><Smartphone class="size-5" /> {{ total > 0 ? `Bayar ${rupiah(total)}` : 'Aktifkan gratis' }}</AppButton>
    </template>
  </AppModal>

  <AppModal v-if="cancelling" size="sm" title="Hentikan langganan?" :description="`Fitur ${sub?.plan} tetap aktif sampai ${date(sub?.current_period_end)}, lalu workspace kembali ke paket gratis. Data Anda tidak dihapus.`" @close="cancelling = false">
    <template #footer>
      <AppButton variant="secondary" @click="cancelling = false">Tetap berlangganan</AppButton>
      <AppButton variant="danger" :loading="busy" @click="toggleCancel(false)">Hentikan di akhir periode</AppButton>
    </template>
  </AppModal>
</template>
