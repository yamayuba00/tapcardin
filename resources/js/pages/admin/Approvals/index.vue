<script setup>
// Persetujuan & tinjauan keuangan: (1) permintaan aksi sensitif (lunas manual, batal invoice, beri paket) yang harus
// disetujui ORANG LAIN, (2) refund (diputuskan → transfer ke pembayar asal dicatat), (3) pembayaran bermasalah.
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Check, X } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppCheckbox from '@/components/ui/AppCheckbox/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { useAction } from '@/composables/useAsync'
import { usePagedList } from '@/composables/usePagedList'
import { dateTime, rupiah } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const TABS = [{ value: 'approvals', label: 'Permintaan' }, { value: 'refunds', label: 'Refund' }, { value: 'reviews', label: 'Pembayaran bermasalah' }]
const tab = ref(TABS.some((t) => t.value === route.query.tab) ? route.query.tab : 'approvals')
const setTab = (v) => { tab.value = v; router.replace({ query: { tab: v } }) }

const approvals = usePagedList(adminApi.approvals, { status: 'pending' }, { url: false, sort: '-created_at', perPage: 25 })
const refunds = usePagedList(adminApi.refunds, { status: 'requested' }, { url: false, sort: '-created_at', perPage: 25 })
const reviews = usePagedList(adminApi.paymentReviews, { status: 'open' }, { url: false, sort: '-created_at', perPage: 25 })

const TONE = { pending: 'warn', requested: 'warn', approved: 'ok', paid: 'ok', rejected: 'muted', cancelled: 'muted', expired: 'muted', failed: 'danger', open: 'warn', resolved: 'ok', dismissed: 'muted' }
const A_COLS = [{ key: 'summary', label: 'Permintaan' }, { key: 'requested_by', label: 'Pengaju' }, { key: 'status', label: 'Status' }, { key: 'created_at', label: 'Diajukan', sortable: true }, { key: 'act', label: '' }]
const R_COLS = [{ key: 'order', label: 'Pesanan' }, { key: 'amount', label: 'Nominal', align: 'right', sortable: true }, { key: 'reason_label', label: 'Alasan' }, { key: 'status', label: 'Status' }, { key: 'act', label: '' }]
const P_COLS = [{ key: 'message', label: 'Masalah' }, { key: 'kind_label', label: 'Jenis' }, { key: 'status', label: 'Status' }, { key: 'created_at', label: 'Waktu', sortable: true }, { key: 'act', label: '' }]

const { busy, run } = useAction()
const modal = ref(null) // { kind: 'approve'|'reject'|'payout'|'resolve', scope, row }
const form = reactive({ note: '', method: 'Transfer bank', reference: '', confirm: false, outcome: 'resolved' })
function open(kind, scope, row) {
  Object.assign(form, { note: '', method: 'Transfer bank', reference: '', confirm: false, outcome: 'resolved' })
  modal.value = { kind, scope, row }
}
const lists = { approvals, refunds, reviews }
async function submit() {
  const { kind, scope, row } = modal.value
  const calls = {
    'approvals.approve': () => adminApi.approveRequest(row.id, form.note),
    'approvals.reject': () => adminApi.rejectRequest(row.id, form.note),
    'refunds.approve': () => adminApi.approveRefund(row.id, form.note),
    'refunds.reject': () => adminApi.rejectRefund(row.id, form.note),
    'refunds.payout': () => adminApi.payoutRefund(row.id, { method: form.method, reference: form.reference, to_original_payer: form.confirm }),
    'reviews.resolve': () => adminApi.resolvePaymentReview(row.id, { outcome: form.outcome, note: form.note }),
  }
  const done = await run(calls[`${scope}.${kind}`], 'Tersimpan.').catch(() => null)
  if (done) { modal.value = null; lists[scope].reload() }
}
async function cancel(scope, row) {
  const fn = scope === 'approvals' ? adminApi.cancelRequest : adminApi.cancelRefund
  if (await run(() => fn(row.id), 'Dibatalkan.').catch(() => null)) lists[scope].reload()
}
const needsNote = () => ['reject', 'resolve'].includes(modal.value?.kind)
</script>

<template>
  <PageHeader eyebrow="Bisnis" title="Persetujuan & tinjauan" />
  <p class="rounded-md border border-line bg-surface-2 px-4 py-3 text-sm text-muted">
    Aksi keuangan sensitif tidak langsung berjalan: harus disetujui <b>orang lain</b>. Refund hanya dikembalikan ke pembayar asal.
  </p>
  <AppSegmented :model-value="tab" label="Antrean" :options="TABS" @update:model-value="setTab" />

  <!-- permintaan -->
  <template v-if="tab === 'approvals'">
    <AppSegmented v-model="approvals.filters.status" label="Status" :options="[{ value: 'pending', label: 'Menunggu' }, { value: '', label: 'Semua' }]" @update:model-value="approvals.search" />
    <DataTable dense :columns="A_COLS" :rows="approvals.rows.value" :meta="approvals.meta.value" :sort="approvals.sort.value" :loading="approvals.loading.value"
      empty-text="Tidak ada permintaan." @sort="approvals.toggleSort" @page="approvals.goto" @per-page="approvals.setPerPage">
      <template #cell-summary="{ row }">
        <span class="font-medium">{{ row.summary }}</span>
        <span class="block text-xs text-muted">{{ row.type_label }} · ref <span class="font-mono">{{ row.reference }}</span></span>
        <span v-if="row.reason" class="block text-xs text-muted">Alasan: {{ row.reason }}</span>
        <span v-if="row.decision_note" class="block text-xs text-muted">Catatan: {{ row.decision_note }}</span>
      </template>
      <template #cell-requested_by="{ row }">{{ row.requested_by }}<span v-if="row.mine" class="block text-xs text-accent-text">Anda</span></template>
      <template #cell-status="{ row }">
        <AppBadge :tone="TONE[row.status]">{{ row.status_label }}</AppBadge>
        <span v-if="row.self_approved" class="block text-xs text-warn">disetujui sendiri (satu-satunya staf berwenang)</span>
        <span v-if="row.decided_by" class="block text-xs text-muted">oleh {{ row.decided_by }}</span>
      </template>
      <template #cell-created_at="{ row }"><span class="whitespace-nowrap">{{ dateTime(row.created_at) }}</span></template>
      <template #cell-act="{ row }">
        <div class="flex justify-end gap-1">
          <AppButton v-if="row.can_decide" variant="secondary" @click="open('approve', 'approvals', row)"><Check class="size-4" /> Setujui</AppButton>
          <AppButton v-if="row.can_decide" variant="ghost" @click="open('reject', 'approvals', row)"><X class="size-4" /> Tolak</AppButton>
          <AppButton v-if="row.can_cancel" variant="ghost" @click="cancel('approvals', row)">Batalkan</AppButton>
        </div>
      </template>
    </DataTable>
  </template>

  <!-- refund -->
  <template v-else-if="tab === 'refunds'">
    <AppSegmented v-model="refunds.filters.status" label="Status refund" :options="[{ value: 'requested', label: 'Menunggu keputusan' }, { value: 'approved', label: 'Menunggu transfer' }, { value: 'paid', label: 'Dibayar' }, { value: '', label: 'Semua' }]" @update:model-value="refunds.search" />
    <DataTable dense :columns="R_COLS" :rows="refunds.rows.value" :meta="refunds.meta.value" :sort="refunds.sort.value" :loading="refunds.loading.value"
      empty-text="Tidak ada refund." @sort="refunds.toggleSort" @page="refunds.goto" @per-page="refunds.setPerPage">
      <template #cell-order="{ row }">
        <RouterLink v-if="row.order" :to="`/orders/${row.order.id}`" class="font-mono">{{ row.order.number }}</RouterLink>
        <span class="block text-xs text-muted">{{ row.tenant?.name }} · diajukan {{ row.requested_by }}</span>
      </template>
      <template #cell-amount="{ row }"><span class="font-mono">{{ rupiah(row.amount) }}</span><AppBadge v-if="row.requires_owner" tone="info" class="ml-2">Pemilik</AppBadge></template>
      <template #cell-reason_label="{ row }">{{ row.reason_label }}<span class="block text-xs text-muted">{{ row.reason }}</span>
        <span class="block text-xs text-muted">Kembali ke: {{ row.payer?.name ?? '—' }} · {{ row.payer?.method ?? row.payer?.provider ?? 'pembayar asal' }}<template v-if="row.payer?.email"> · {{ row.payer.email }}</template></span>
      </template>
      <template #cell-status="{ row }">
        <AppBadge :tone="TONE[row.status]">{{ row.status_label }}</AppBadge>
        <span v-if="row.needs_owner_you_lack" class="block text-xs text-warn">perlu Pemilik Platform</span>
        <span v-if="row.payout_reference" class="block text-xs text-muted">ref <span class="font-mono">{{ row.payout_reference }}</span></span>
        <span v-if="row.self_approved" class="block text-xs text-warn">tanpa pemisahan tugas (satu staf)</span>
      </template>
      <template #cell-act="{ row }">
        <div class="flex justify-end gap-1">
          <AppButton v-if="row.can_decide" variant="secondary" @click="open('approve', 'refunds', row)"><Check class="size-4" /> Setujui</AppButton>
          <AppButton v-if="row.can_decide" variant="ghost" @click="open('reject', 'refunds', row)"><X class="size-4" /> Tolak</AppButton>
          <AppButton v-if="row.can_payout" @click="open('payout', 'refunds', row)">Catat transfer</AppButton>
          <AppButton v-if="row.can_cancel" variant="ghost" @click="cancel('refunds', row)">Batalkan</AppButton>
        </div>
      </template>
    </DataTable>
  </template>

  <!-- pembayaran bermasalah -->
  <template v-else>
    <AppSegmented v-model="reviews.filters.status" label="Status tinjauan" :options="[{ value: 'open', label: 'Terbuka' }, { value: '', label: 'Semua' }]" @update:model-value="reviews.search" />
    <DataTable dense :columns="P_COLS" :rows="reviews.rows.value" :meta="reviews.meta.value" :sort="reviews.sort.value" :loading="reviews.loading.value"
      empty-text="Tidak ada pembayaran bermasalah." @sort="reviews.toggleSort" @page="reviews.goto" @per-page="reviews.setPerPage">
      <template #cell-message="{ row }">
        {{ row.message }}
        <span v-if="row.invoice" class="block text-xs"><RouterLink :to="`/finance/invoices/${row.invoice.id}`" class="font-mono">{{ row.invoice.number }}</RouterLink></span>
        <span v-if="row.resolution_note" class="block text-xs text-muted">Penyelesaian: {{ row.resolution_note }}</span>
      </template>
      <template #cell-status="{ row }"><AppBadge :tone="TONE[row.status]">{{ row.status === 'open' ? 'Terbuka' : row.status === 'resolved' ? 'Selesai' : 'Diabaikan' }}</AppBadge></template>
      <template #cell-created_at="{ row }"><span class="whitespace-nowrap">{{ dateTime(row.created_at) }}</span></template>
      <template #cell-act="{ row }">
        <AppButton v-if="row.status === 'open' && auth.can('finance.reviews')" variant="secondary" @click="open('resolve', 'reviews', row)">Selesaikan</AppButton>
      </template>
    </DataTable>
  </template>

  <AppModal
    v-if="modal" size="sm"
    :title="{ approve: 'Setujui', reject: 'Tolak', payout: 'Catat transfer refund', resolve: 'Selesaikan tinjauan' }[modal.kind]"
    :description="modal.kind === 'payout' ? `Dana ${rupiah(modal.row.amount)} dikembalikan HANYA ke pembayar asal. Transfer dilakukan di luar sistem; catat buktinya di sini.` : (modal.row.summary ?? modal.row.message ?? '')"
    @close="modal = null"
  >
    <form id="decide-form" class="grid gap-4" @submit.prevent="submit">
      <template v-if="modal.kind === 'payout'">
        <p class="rounded-md bg-surface-2 px-3 py-2 text-sm">Tujuan: <b>{{ modal.row.payer?.name ?? '—' }}</b> · {{ modal.row.payer?.method ?? modal.row.payer?.provider }} <span v-if="modal.row.payer?.reference" class="font-mono">{{ modal.row.payer.reference }}</span></p>
        <AppField label="Metode"><AppInput v-model="form.method" maxlength="60" required /></AppField>
        <AppField label="Nomor referensi transfer" hint="Wajib; satu referensi hanya untuk satu refund."><AppInput v-model="form.reference" maxlength="80" required /></AppField>
        <AppCheckbox v-model="form.confirm" label="Saya konfirmasi dana dikembalikan ke pembayar asal, bukan rekening lain." />
      </template>
      <template v-else>
        <AppField v-if="modal.kind === 'resolve'" label="Hasil">
          <AppSegmented v-model="form.outcome" label="Hasil" :options="[{ value: 'resolved', label: 'Sudah ditangani' }, { value: 'dismissed', label: 'Abaikan' }]" />
        </AppField>
        <AppField :label="needsNote() ? 'Catatan (wajib)' : 'Catatan (opsional)'"><AppInput v-model="form.note" maxlength="300" :required="needsNote()" /></AppField>
      </template>
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="modal = null">Batal</AppButton>
      <AppButton type="submit" form="decide-form" :variant="modal.kind === 'reject' ? 'danger' : 'primary'" :loading="busy" :disabled="modal.kind === 'payout' && !form.confirm">
        {{ { approve: 'Setujui', reject: 'Tolak', payout: 'Catat transfer', resolve: 'Simpan' }[modal.kind] }}
      </AppButton>
    </template>
  </AppModal>
</template>
