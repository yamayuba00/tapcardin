<script setup>
// Keuangan — MRR/ARR & pendapatan, daftar invoice (tandai lunas manual/batalkan di detail), langganan, event gateway,
// dan "beri paket" (kompensasi/mitra) tanpa pembayaran — tercatat sebagai invoice penyesuaian Rp0 + audit.
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Gift } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import KpiCard from '@/components/common/KpiCard/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import TimeBars from '@/components/charts/TimeBars/index.vue'
import { adminApi } from '@/api/admin'
import { useAction, useAsync } from '@/composables/useAsync'
import { usePagedList } from '@/composables/usePagedList'
import { date, dateTime, delta, num, rupiah } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const tab = ref(['invoices', 'subscriptions', 'events'].includes(route.query.tab) ? route.query.tab : 'invoices')
const setTab = (v) => { tab.value = v; router.replace({ query: { tab: v } }) }

const { data: m } = useAsync(() => adminApi.financeSummary())
const invoices = usePagedList(adminApi.financeInvoices, { status: '', q: '' }, { url: false, sort: '-created_at', perPage: 25 })
const subs = usePagedList(adminApi.subscriptions, { status: '' }, { url: false, sort: 'current_period_end', perPage: 25 })
const events = usePagedList(adminApi.paymentEvents, {}, { url: false, sort: '-created_at', perPage: 25 })

const INV = { issued: ['warn', 'Belum dibayar'], overdue: ['danger', 'Terlambat'], paid: ['ok', 'Lunas'], void: ['muted', 'Dibatalkan'], draft: ['muted', 'Draf'] }
const SUB = { active: ['ok', 'Aktif'], trialing: ['info', 'Uji coba'], past_due: ['danger', 'Menunggak'], cancelled: ['muted', 'Berhenti'], expired: ['muted', 'Berakhir'] }
const INV_COLUMNS = [
  { key: 'number', label: 'Nomor', sortable: true }, { key: 'tenant', label: 'Workspace' }, { key: 'plan', label: 'Paket' },
  { key: 'total', label: 'Total', sortable: true, align: 'right' }, { key: 'status', label: 'Status' }, { key: 'created_at', label: 'Dibuat', sortable: true },
]
const SUB_COLUMNS = [
  { key: 'tenant', label: 'Workspace' }, { key: 'plan', label: 'Paket' }, { key: 'status', label: 'Status' },
  { key: 'current_period_end', label: 'Berakhir', sortable: true },
]
const EVT_COLUMNS = [
  { key: 'created_at', label: 'Waktu', sortable: true }, { key: 'provider', label: 'Gateway' }, { key: 'type', label: 'Status' },
  { key: 'invoice', label: 'Invoice' }, { key: 'amount', label: 'Nominal', align: 'right' }, { key: 'check', label: 'Verifikasi' },
]
const growth = computed(() => (m.value ? delta(m.value.revenue_month, m.value.revenue_prev_month) : null))

// beri paket
const { busy, run } = useAction()
const granting = ref(false)
const grant = reactive({ tenant: '', plan: 'personal_pro', months: 1, reason: '', reference: '' })
const { data: plans } = useAsync(() => (auth.can('plans.manage') ? adminApi.plans() : Promise.resolve({ data: [] })))
const planOptions = computed(() => (plans.value ?? []).map((p) => ({ value: p.key, label: `${p.name} (${p.tenant_type === 'personal' ? 'personal' : 'perusahaan'})` })))
const grantErrors = ref({})
async function doGrant() {
  grantErrors.value = {}
  try {
    await run(() => adminApi.grantPlan({ ...grant, months: Number(grant.months) }), 'Permintaan dikirim — menunggu persetujuan staf lain.')
    granting.value = false
    router.push('/approvals')
  } catch (e) { grantErrors.value = e.fields ?? {} }
}
</script>

<template>
  <PageHeader eyebrow="Bisnis" title="Keuangan">
    <AppButton v-if="auth.can('finance.invoices')" variant="secondary" @click="granting = true"><Gift class="size-5" /> Ajukan beri paket</AppButton>
  </PageHeader>

  <div v-if="m" class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
    <KpiCard label="MRR" :value="rupiah(m.mrr)" :hint="`ARR ${rupiah(m.arr)}`" />
    <KpiCard label="Pendapatan bulan ini" :value="rupiah(m.revenue_month)" :delta="growth" :hint="`Bulan lalu ${rupiah(m.revenue_prev_month)}`" />
    <KpiCard label="Langganan aktif" :value="num(m.active_subscriptions)" :hint="m.past_due ? `${m.past_due} menunggak` : 'Tidak ada tunggakan'" />
    <KpiCard label="Tagihan terbuka" :value="num(m.open_invoices)" :hint="rupiah(m.open_amount)" />
  </div>
  <AppPanel v-if="m" title="Pendapatan 30 hari">
    <TimeBars :series="m.series" label="Pendapatan lunas per hari" :format="rupiah" />
  </AppPanel>

  <AppSegmented :model-value="tab" label="Data" :options="[{ value: 'invoices', label: 'Invoice' }, { value: 'subscriptions', label: 'Langganan' }, { value: 'events', label: 'Event gateway' }]" @update:model-value="setTab" />

  <template v-if="tab === 'invoices'">
    <form class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap" @submit.prevent="invoices.search">
      <AppInput v-model="invoices.filters.q" placeholder="Nomor invoice / workspace" aria-label="Cari invoice" class="col-span-2 sm:max-w-[260px]" />
      <AppSelect v-model="invoices.filters.status" placeholder="Semua status" class="sm:!w-auto" aria-label="Status"
        :options="Object.entries(INV).map(([value, [, label]]) => ({ value, label }))" @update:model-value="invoices.search" />
      <AppButton type="submit" variant="secondary" class="col-span-2 sm:col-auto">Saring</AppButton>
    </form>
    <DataTable dense :columns="INV_COLUMNS" :rows="invoices.rows.value" :meta="invoices.meta.value" :sort="invoices.sort.value" :loading="invoices.loading.value"
      empty-text="Belum ada invoice." @sort="invoices.toggleSort" @page="invoices.goto" @per-page="invoices.setPerPage">
      <template #cell-number="{ row }"><RouterLink :to="`/finance/invoices/${row.id}`" class="font-mono">{{ row.number }}</RouterLink></template>
      <template #cell-tenant="{ row }"><RouterLink v-if="row.tenant" :to="`/tenants/${row.tenant.id}`">{{ row.tenant.name }}</RouterLink></template>
      <template #cell-plan="{ row }">{{ row.plan ?? '—' }}<span v-if="row.type === 'adjustment'" class="block text-xs text-muted">penyesuaian</span></template>
      <template #cell-total="{ row }"><span class="font-mono">{{ rupiah(row.total) }}</span></template>
      <template #cell-status="{ row }"><AppBadge :tone="INV[row.status]?.[0]">{{ INV[row.status]?.[1] ?? row.status }}</AppBadge></template>
      <template #cell-created_at="{ row }"><span class="whitespace-nowrap">{{ dateTime(row.created_at) }}</span></template>
    </DataTable>
  </template>

  <template v-else-if="tab === 'subscriptions'">
    <div class="flex flex-wrap gap-2">
      <AppSelect v-model="subs.filters.status" placeholder="Semua status" class="!w-auto" aria-label="Status langganan"
        :options="Object.entries(SUB).map(([value, [, label]]) => ({ value, label }))" @update:model-value="subs.search" />
    </div>
    <DataTable dense :columns="SUB_COLUMNS" :rows="subs.rows.value" :meta="subs.meta.value" :sort="subs.sort.value" :loading="subs.loading.value"
      empty-text="Belum ada langganan." @sort="subs.toggleSort" @page="subs.goto" @per-page="subs.setPerPage">
      <template #cell-tenant="{ row }"><RouterLink :to="`/tenants/${row.tenant.id}`">{{ row.tenant.name }}</RouterLink></template>
      <template #cell-plan="{ row }">{{ row.plan }} · {{ row.cycle === 'yearly' ? 'tahunan' : 'bulanan' }}</template>
      <template #cell-status="{ row }">
        <AppBadge :tone="SUB[row.status]?.[0]">{{ SUB[row.status]?.[1] ?? row.status }}</AppBadge>
        <span v-if="row.cancel_at_period_end" class="block text-xs text-muted">berhenti di akhir periode</span>
      </template>
      <template #cell-current_period_end="{ row }">{{ date(row.current_period_end) }}<span v-if="row.grace_until" class="block text-xs text-danger">tenggang s/d {{ date(row.grace_until) }}</span></template>
    </DataTable>
  </template>

  <DataTable v-else dense :columns="EVT_COLUMNS" :rows="events.rows.value" :meta="events.meta.value" :sort="events.sort.value" :loading="events.loading.value"
    empty-text="Belum ada event pembayaran." @sort="events.toggleSort" @page="events.goto" @per-page="events.setPerPage">
    <template #cell-created_at="{ row }"><span class="font-mono whitespace-nowrap">{{ dateTime(row.created_at) }}</span></template>
    <template #cell-provider="{ row }"><span class="font-mono">{{ row.provider }}</span></template>
    <template #cell-invoice="{ row }"><RouterLink v-if="row.invoice" :to="`/finance/invoices/${row.invoice.id}`" class="font-mono">{{ row.invoice.number }}</RouterLink><span v-else class="text-muted">tidak dikenal</span></template>
    <template #cell-amount="{ row }"><span class="font-mono">{{ row.amount != null ? rupiah(row.amount) : '—' }}</span></template>
    <template #cell-check="{ row }">
      <AppBadge :tone="!row.signature_valid ? 'danger' : row.processed ? 'ok' : 'muted'">{{ !row.signature_valid ? 'Signature salah' : row.processed ? 'Diproses' : 'Dicatat' }}</AppBadge>
    </template>
  </DataTable>

  <AppModal v-if="granting" title="Ajukan pemberian paket" description="Untuk kompensasi, mitra, atau uji coba khusus. Tidak langsung berjalan: staf lain harus menyetujui dulu di menu Persetujuan." @close="granting = false">
    <form id="grant-form" class="grid gap-4" @submit.prevent="doGrant">
      <AppField label="ID workspace" hint="Salin dari halaman detail workspace (26 karakter)." :error="grantErrors.tenant?.[0]"><AppInput v-model="grant.tenant" code maxlength="26" required /></AppField>
      <div class="grid gap-4 sm:grid-cols-[1fr_140px]">
        <AppField label="Paket" :error="grantErrors.plan?.[0]">
          <AppSelect v-if="planOptions.length" v-model="grant.plan" :options="planOptions" />
          <AppInput v-else v-model="grant.plan" code />
        </AppField>
        <AppField label="Durasi (bulan)" :error="grantErrors.months?.[0]"><AppInput v-model="grant.months" type="number" min="1" max="36" /></AppField>
      </div>
      <AppField label="Alasan (wajib)" :error="grantErrors.reason?.[0]"><AppInput v-model="grant.reason" maxlength="200" minlength="5" required /></AppField>
      <AppField label="Nomor tiket / referensi (wajib)" :error="grantErrors.reference?.[0]"><AppInput v-model="grant.reference" maxlength="80" minlength="3" required /></AppField>
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="granting = false">Batal</AppButton>
      <AppButton type="submit" form="grant-form" :loading="busy">Ajukan</AppButton>
    </template>
  </AppModal>
</template>
