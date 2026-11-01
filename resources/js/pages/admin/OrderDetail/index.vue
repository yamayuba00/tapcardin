<script setup>
// Panel → detail pesanan: desain yang dibekukan (unduh file cetak), alamat kirim, linimasa, dan langkah produksi:
// Mulai produksi (batch per item kartu) → token/encode/QC di menu Produksi → Kirim (resi) → Diterima. Batal sebelum produksi.
import { reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { Ban, Boxes, Check, Factory, Printer, ShieldAlert, Truck, Undo2 } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import OrderItems from '@/components/orders/OrderItems/index.vue'
import OrderTimeline from '@/components/orders/OrderTimeline/index.vue'
import PrintPreview from '@/components/studio/PrintPreview/index.vue'
import { adminApi } from '@/api/admin'
import { useAction, useAsync } from '@/composables/useAsync'
import { dateTime, rupiah } from '@/lib/format'
import { ORDER_STATUS } from '@/lib/orders'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()
const id = route.params.id
const { data: o } = useAsync(() => adminApi.order(id))
const { busy, run } = useAction()
const shipping = ref(false)
const ship = reactive({ courier: '', tracking_number: '' })
const cancelling = ref(false)
const reason = ref('')
const printing = ref(null) // item yang sedang dipratinjau/diunduh file cetaknya

async function act(fn, message) {
  const res = await run(fn, message).catch(() => null)
  if (res) o.value = res.data
  return res
}
const start = () => act(() => adminApi.startProduction(id), 'Produksi dimulai — batch kartu dibuat.')
const deliver = () => act(() => adminApi.deliverOrder(id), 'Pesanan ditandai diterima.')
async function doShip() {
  if (await act(() => adminApi.shipOrder(id, ship), 'Pesanan dikirim. Pelanggan diberi tahu.')) shipping.value = false
}
async function doCancel() {
  if (await act(() => adminApi.cancelOrder(id, reason.value), 'Pesanan dibatalkan.')) cancelling.value = false
}
const sidesOf = (it) => ({ front: it.design.front, back: it.design.back })

// tinjauan risiko & refund
const reviewing = ref('')
const reviewNote = ref('')
async function doReview() {
  if (await act(() => adminApi.reviewOrder(id, { decision: reviewing.value, note: reviewNote.value }), 'Tinjauan tersimpan.')) { reviewing.value = ''; reviewNote.value = '' }
}
const refunding = ref(false)
const refund = reactive({ reason_code: '', reason: '', amount: '', evidence: '' })
function openRefund() {
  refund.reason_code = Object.keys(o.value.refund.allowed_reasons)[0] ?? ''
  refunding.value = true
}
async function doRefund() {
  const body = { reason_code: refund.reason_code, reason: refund.reason }
  if (refund.amount) body.amount = Number(refund.amount)
  if (refund.evidence.trim()) body.evidence = [refund.evidence.trim()]
  const res = await run(() => adminApi.requestRefund(id, body), 'Refund diajukan — menunggu keputusan staf lain.').catch(() => null)
  if (res) { refunding.value = false; o.value = (await adminApi.order(id)).data }
}
</script>

<template>
  <AppSkeleton v-if="!o" class="!h-96" />
  <template v-else>
    <PageHeader :title="o.number" back="/orders" back-label="Pesanan">
      <template #meta>
        <div class="flex flex-wrap gap-2">
          <AppBadge :tone="ORDER_STATUS[o.status]?.tone">{{ o.status_label }}</AppBadge>
          <AppBadge tone="outline" :dot="false">{{ o.tenant.name }}</AppBadge>
        </div>
      </template>
      <template v-if="auth.can('orders.manage')">
        <AppButton v-if="['pending_payment', 'paid'].includes(o.status)" variant="ghost" @click="cancelling = true"><Ban class="size-5" /> Batalkan</AppButton>
        <AppButton v-if="o.status === 'paid'" :loading="busy" :disabled="o.review.status === 'pending'" :title="o.review.status === 'pending' ? 'Tinjau pesanan dulu' : undefined" @click="start"><Factory class="size-5" /> Mulai produksi</AppButton>
        <AppButton v-if="o.status === 'in_production'" @click="shipping = true"><Truck class="size-5" /> Kirim</AppButton>
        <AppButton v-if="o.status === 'shipped'" :loading="busy" @click="deliver"><Check class="size-5" /> Tandai diterima</AppButton>
      </template>
    </PageHeader>

    <!-- tinjauan risiko sebelum produksi -->
    <AppPanel v-if="o.review.status === 'pending' || o.review.status === 'rejected' || (o.review.status === 'approved' && o.review.flags.length)" title="Tinjauan sebelum produksi">
      <div class="grid gap-4">
        <ul v-if="o.review.flags.length" class="grid gap-1.5 text-sm">
          <li v-for="f in o.review.flags" :key="f.code" class="flex items-start gap-2"><ShieldAlert class="mt-0.5 size-4 flex-none text-warn" /> {{ f.label }}</li>
        </ul>
        <dl class="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-sm">
          <dt class="text-muted">Akun dibuat</dt><dd>{{ o.review.customer_since ? dateTime(o.review.customer_since) : '—' }}</dd>
          <dt class="text-muted">Skor risiko</dt><dd class="font-mono">{{ o.review.score }}</dd>
        </dl>
        <div v-if="o.review.texts.length" class="grid gap-1.5">
          <span class="label">Semua teks yang akan tercetak — periksa merek dagang / konten terlarang</span>
          <ul class="flex flex-wrap gap-1.5"><li v-for="t in o.review.texts" :key="t" class="rounded-sm bg-surface-2 px-2 py-1 font-mono text-xs">{{ t }}</li></ul>
        </div>
        <p v-if="o.review.status !== 'pending'" class="text-sm text-muted">
          {{ o.review.status === 'approved' ? 'Disetujui' : 'Ditolak' }} oleh {{ o.review.reviewed_by }} · {{ o.review.note }}
        </p>
        <div v-else-if="auth.can('orders.review')" class="flex flex-wrap gap-2">
          <AppButton @click="reviewing = 'approve'"><Check class="size-5" /> Setujui untuk produksi</AppButton>
          <AppButton variant="danger" @click="reviewing = 'reject'"><Ban class="size-5" /> Tolak & ajukan refund</AppButton>
        </div>
        <p v-else class="text-sm text-warn">Menunggu staf dengan izin “Review desain”.</p>
      </div>
    </AppPanel>

    <p v-if="o.status === 'in_production'" class="flex items-start gap-3 rounded-md border border-line-strong bg-surface-2 px-4 py-3 text-sm">
      <Boxes class="mt-0.5 size-5 flex-none text-info" />
      <span>Lanjutkan di menu Produksi: buat token, cetak, encode NFC, dan QC setiap batch di bawah. Setelah semua kartu lolos QC, klik <b>Kirim</b> di sini.</span>
    </p>
    <p v-if="o.status === 'cancelled' && o.cancel_reason" class="rounded-md border border-line-strong px-4 py-3 text-sm">Dibatalkan: {{ o.cancel_reason }}{{ o.timeline.find((t) => t.key === 'paid')?.at ? ' — sudah dibayar: refund ke pembayar asal otomatis diajukan (lihat panel Refund).' : '' }}</p>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_340px] lg:items-start">
      <div class="grid gap-4">
        <AppPanel title="Item & desain cetak">
          <div class="grid gap-4">
            <OrderItems :items="o.items" :qr="o.qr" />
            <div class="flex flex-wrap gap-2 border-t border-line pt-3">
              <AppButton v-for="it in o.items" :key="it.id" variant="secondary" @click="printing = it"><Printer class="size-4" /> File cetak: {{ it.design.name }}</AppButton>
            </div>
            <dl class="grid gap-1 text-sm">
              <div class="flex justify-between"><dt class="text-muted">Subtotal</dt><dd class="font-mono">{{ rupiah(o.subtotal) }}</dd></div>
              <div class="flex justify-between"><dt class="text-muted">{{ o.shipping.method }}</dt><dd class="font-mono">{{ rupiah(o.shipping_fee) }}</dd></div>
              <div class="flex justify-between font-semibold"><dt>Total</dt><dd class="font-mono">{{ rupiah(o.total) }}</dd></div>
            </dl>
          </div>
        </AppPanel>

        <AppPanel v-if="o.batches.length" title="Batch produksi" flush>
          <ul>
            <li v-for="b in o.batches" :key="b.id" class="flex items-center justify-between gap-3 border-b border-line px-5 py-3 last:border-b-0">
              <RouterLink :to="`/batches/${b.id}`" class="font-mono">{{ b.code }}</RouterLink>
              <span class="text-sm text-muted">{{ b.quantity }} kartu</span>
              <AppBadge tone="info">{{ b.status_label }}</AppBadge>
            </li>
          </ul>
        </AppPanel>
      </div>

      <div class="grid gap-4">
        <AppPanel title="Status"><OrderTimeline :timeline="o.timeline" /></AppPanel>
        <AppPanel v-if="o.refund.current || (auth.can('finance.refund') && Object.keys(o.refund.allowed_reasons).length)" title="Refund">
          <div class="grid gap-3 text-sm">
            <template v-if="o.refund.current">
              <div class="flex flex-wrap items-center gap-2">
                <AppBadge :tone="{ paid: 'ok', approved: 'ok', requested: 'warn' }[o.refund.current.status] ?? 'muted'">{{ o.refund.current.status_label }}</AppBadge>
                <span class="font-mono">{{ rupiah(o.refund.current.amount) }}</span>
              </div>
              <p class="text-muted">{{ o.refund.current.reason_label }} — {{ o.refund.current.reason }}</p>
              <p class="text-muted">Dana kembali ke pembayar asal: {{ o.refund.current.payer?.name ?? '—' }} · {{ o.refund.current.payer?.method ?? o.refund.current.payer?.provider }}</p>
              <RouterLink to="/approvals?tab=refunds" class="font-semibold">Buka antrean refund</RouterLink>
            </template>
            <template v-else>
              <p class="text-muted">Dana hanya dikembalikan ke pembayar asal. Maksimal {{ rupiah(o.refund.refundable) }}. Diputuskan staf lain<template v-if="o.refund.refundable >= o.refund.owner_threshold"> (nominal ini perlu Pemilik)</template>.</p>
              <div><AppButton variant="secondary" @click="openRefund"><Undo2 class="size-5" /> Ajukan refund</AppButton></div>
            </template>
          </div>
        </AppPanel>
        <AppPanel title="Pelanggan & pengiriman">
          <div class="grid gap-2 text-sm">
            <p><RouterLink v-if="o.customer" :to="`/users/${o.customer.id}`">{{ o.customer.name }}</RouterLink> · {{ o.customer?.email }}</p>
            <p class="font-medium">{{ o.shipping.method }}</p>
            <template v-if="o.shipping.needs_address">
              <p>{{ o.shipping.recipient_name }} · {{ o.shipping.recipient_phone }}</p>
              <p class="text-muted">{{ o.shipping.address_line }}, {{ o.shipping.city }}{{ o.shipping.province ? `, ${o.shipping.province}` : '' }} {{ o.shipping.postal_code }}</p>
            </template>
            <p v-if="o.shipping.notes" class="text-muted">Catatan: {{ o.shipping.notes }}</p>
            <p v-if="o.shipping.tracking_number">Resi {{ o.shipping.courier }} <span class="font-mono font-semibold">{{ o.shipping.tracking_number }}</span></p>
            <p v-if="o.invoice" class="text-muted">Invoice <RouterLink :to="`/finance/invoices/${o.invoice.id}`" class="font-mono">{{ o.invoice.number }}</RouterLink> · {{ o.invoice.status }}</p>
          </div>
        </AppPanel>
      </div>
    </div>

    <PrintPreview
      v-if="printing" :kind="printing.kind === 'lanyard' ? 'lanyard' : 'card'" :print-sides="printing.design.print_sides ?? 2"
      :name="`${o.number} ${printing.design.name}`" :sides="sidesOf(printing)" :kit="printing.design.kit" :vars="printing.design.vars" :qr="o.qr"
      @close="printing = null"
    />

    <AppModal v-if="shipping" size="sm" title="Kirim pesanan" description="Kartu yang lolos QC di batch pesanan ini ditandai terkirim dan siap diaktifkan pelanggan." @close="shipping = false">
      <form id="ship-form" class="grid gap-4" @submit.prevent="doShip">
        <AppField label="Kurir"><AppInput v-model="ship.courier" maxlength="40" placeholder="mis. JNE, SiCepat" /></AppField>
        <AppField :label="o.shipping.needs_address ? 'Nomor resi' : 'Nomor resi (opsional — ambil sendiri)'"><AppInput v-model="ship.tracking_number" maxlength="60" /></AppField>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="shipping = false">Batal</AppButton>
        <AppButton type="submit" form="ship-form" :loading="busy"><Truck class="size-5" /> Kirim</AppButton>
      </template>
    </AppModal>

    <AppModal v-if="reviewing" size="sm" :title="reviewing === 'approve' ? 'Setujui untuk produksi' : 'Tolak pesanan'" :description="reviewing === 'reject' ? 'Pesanan dibatalkan dan refund penuh ke pembayar asal otomatis diajukan (diputuskan staf keuangan).' : 'Pastikan isi desain, alamat, dan riwayat akun sudah diperiksa.'" @close="reviewing = ''">
      <form id="review-form" @submit.prevent="doReview"><AppField label="Catatan tinjauan (wajib)"><AppInput v-model="reviewNote" maxlength="300" minlength="5" required /></AppField></form>
      <template #footer>
        <AppButton variant="secondary" @click="reviewing = ''">Kembali</AppButton>
        <AppButton type="submit" form="review-form" :variant="reviewing === 'reject' ? 'danger' : 'primary'" :loading="busy">{{ reviewing === 'approve' ? 'Setujui' : 'Tolak pesanan' }}</AppButton>
      </template>
    </AppModal>

    <AppModal v-if="refunding" size="sm" title="Ajukan refund" description="Dana hanya ke pembayar asal. Tidak ada kolom rekening tujuan." @close="refunding = false">
      <form id="refund-form" class="grid gap-4" @submit.prevent="doRefund">
        <AppField label="Alasan">
          <AppSelect v-model="refund.reason_code" :options="Object.entries(o.refund.allowed_reasons).map(([value, label]) => ({ value, label }))" />
        </AppField>
        <AppField label="Nominal" :hint="`Kosongkan untuk penuh (${rupiah(o.refund.refundable)})`"><AppInput v-model="refund.amount" type="number" min="1" :max="o.refund.refundable" /></AppField>
        <AppField label="Penjelasan (wajib)"><AppInput v-model="refund.reason" maxlength="500" minlength="5" required /></AppField>
        <AppField v-if="['defect', 'wrong_print', 'not_delivered'].includes(refund.reason_code)" label="Bukti (tautan foto / video)" hint="Wajib untuk klaim ini."><AppInput v-model="refund.evidence" maxlength="300" /></AppField>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="refunding = false">Batal</AppButton>
        <AppButton type="submit" form="refund-form" :loading="busy">Ajukan refund</AppButton>
      </template>
    </AppModal>

    <AppModal v-if="cancelling" size="sm" title="Batalkan pesanan?" :description="o.status === 'paid' ? 'Pesanan sudah dibayar — refund penuh ke pembayar asal otomatis diajukan dan diputuskan staf lain.' : 'Invoice yang masih terbuka ikut dibatalkan.'" @close="cancelling = false">
      <form id="cancel-form" @submit.prevent="doCancel"><AppField label="Alasan (dilihat pelanggan)"><AppInput v-model="reason" maxlength="200" required /></AppField></form>
      <template #footer>
        <AppButton variant="secondary" @click="cancelling = false">Kembali</AppButton>
        <AppButton type="submit" form="cancel-form" variant="danger" :loading="busy">Batalkan pesanan</AppButton>
      </template>
    </AppModal>
  </template>
</template>
