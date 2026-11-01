<script setup>
// Detail invoice (panel) — baris tagihan, data penagihan, riwayat event gateway; tandai lunas manual (transfer)
// lewat jalur pembayaran yang sama dengan webhook, atau batalkan dengan alasan.
import { reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { Ban, CircleCheck } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { useAction, useAsync } from '@/composables/useAsync'
import { dateTime, rupiah } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()
const id = route.params.id
const { data: inv } = useAsync(() => adminApi.financeInvoice(id))
const { busy, run } = useAction()
const STATUS = { issued: ['warn', 'Belum dibayar'], overdue: ['danger', 'Terlambat'], paid: ['ok', 'Lunas'], void: ['muted', 'Dibatalkan'], draft: ['muted', 'Draf'] }
const payable = () => ['issued', 'overdue'].includes(inv.value?.status)

const marking = ref(false)
const paid = reactive({ method: 'Transfer bank', reference: '' })
const voiding = ref(false)
const reason = ref('')
const voidRef = ref('')
// Aksi sensitif tidak langsung berjalan: dikirim sebagai permintaan, lalu disetujui orang lain di menu Persetujuan.
const sent = ref('')
async function markPaid() {
  const res = await run(() => adminApi.markInvoicePaid(id, paid), 'Permintaan dikirim — menunggu persetujuan.').catch(() => null)
  if (res) { sent.value = 'Permintaan “tandai lunas” menunggu persetujuan staf lain.'; marking.value = false }
}
async function doVoid() {
  const res = await run(() => adminApi.voidInvoice(id, { reason: reason.value, reference: voidRef.value }), 'Permintaan dikirim — menunggu persetujuan.').catch(() => null)
  if (res) { sent.value = 'Permintaan pembatalan invoice menunggu persetujuan staf lain.'; voiding.value = false }
}
</script>

<template>
  <AppSkeleton v-if="!inv" class="!h-60" />
  <template v-else>
    <PageHeader :title="inv.number" back="/finance" back-label="Keuangan">
      <template #meta>
        <div class="flex flex-wrap gap-2">
          <AppBadge :tone="STATUS[inv.status]?.[0]">{{ STATUS[inv.status]?.[1] ?? inv.status }}</AppBadge>
          <AppBadge tone="outline">{{ inv.type === 'adjustment' ? 'Penyesuaian' : 'Langganan' }}</AppBadge>
        </div>
      </template>
      <template v-if="payable() && auth.can('finance.invoices')">
        <AppButton variant="secondary" @click="voiding = true"><Ban class="size-5" /> Ajukan batal</AppButton>
        <AppButton @click="marking = true"><CircleCheck class="size-5" /> Ajukan lunas manual</AppButton>
      </template>
    </PageHeader>
    <p v-if="sent" class="flex items-center gap-2 rounded-md border border-line-strong bg-surface-2 px-4 py-3 text-sm" role="status">
      {{ sent }} <RouterLink to="/approvals" class="ml-auto font-semibold">Buka Persetujuan</RouterLink>
    </p>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
      <AppPanel title="Rincian">
        <table class="w-full text-sm">
          <tbody>
            <tr v-for="(l, i) in inv.lines" :key="i" class="border-b border-line">
              <td class="py-3">{{ l.label }}<span v-if="l.note" class="block text-xs text-muted">{{ l.note }}</span></td>
              <td class="py-3 text-right font-mono" :class="l.amount < 0 ? 'text-ok' : ''">{{ l.amount < 0 ? '−' + rupiah(-l.amount) : rupiah(l.amount) }}</td>
            </tr>
            <tr v-if="inv.discount"><td class="pt-3 text-muted">Diskon</td><td class="pt-3 text-right font-mono">−{{ rupiah(inv.discount) }}</td></tr>
            <tr><td class="pt-3 font-semibold">Total</td><td class="pt-3 text-right font-display text-xl">{{ rupiah(inv.total) }}</td></tr>
          </tbody>
        </table>
      </AppPanel>

      <AppPanel title="Info">
        <dl class="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-2 text-sm">
          <dt class="text-muted">Workspace</dt><dd><RouterLink v-if="inv.tenant" :to="`/tenants/${inv.tenant.id}`">{{ inv.tenant.name }}</RouterLink></dd>
          <dt class="text-muted">Ditagihkan ke</dt><dd>{{ inv.billing_snapshot?.name ?? '—' }}<span class="block text-muted">{{ inv.billing_snapshot?.email }}</span></dd>
          <dt class="text-muted">Paket</dt><dd>{{ inv.plan ?? '—' }} · {{ inv.cycle === 'yearly' ? 'tahunan' : 'bulanan' }}</dd>
          <dt class="text-muted">Diterbitkan</dt><dd>{{ dateTime(inv.issued_at) }}</dd>
          <dt class="text-muted">Jatuh tempo</dt><dd>{{ dateTime(inv.due_at) }}</dd>
          <template v-if="inv.paid_at"><dt class="text-muted">Dibayar</dt><dd>{{ dateTime(inv.paid_at) }} · {{ inv.payment_method }}</dd></template>
          <dt class="text-muted">Gateway</dt><dd class="font-mono">{{ inv.provider ?? '—' }}<span v-if="inv.payment_ref" class="block break-all text-xs text-muted">{{ inv.payment_ref }}</span></dd>
        </dl>
      </AppPanel>
    </div>

    <AppPanel title="Riwayat event pembayaran" flush>
      <ul>
        <li v-for="e in inv.events" :key="e.id" class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3 text-sm last:border-b-0">
          <span class="font-mono text-muted">{{ dateTime(e.created_at) }}</span>
          <span class="font-mono">{{ e.provider }}</span>
          <AppBadge :tone="e.type === 'paid' ? 'ok' : e.type === 'failed' ? 'danger' : 'muted'">{{ e.type }}</AppBadge>
          <AppBadge v-if="!e.signature_valid" tone="danger">signature salah</AppBadge>
          <span class="ml-auto font-mono">{{ e.amount != null ? rupiah(e.amount) : '' }}</span>
        </li>
        <li v-if="!inv.events.length" class="px-5 py-4 text-muted">Belum ada notifikasi dari gateway.</li>
      </ul>
    </AppPanel>

    <AppModal v-if="marking" size="sm" title="Tandai lunas" :description="`Konfirmasi dana ${rupiah(inv.total)} sudah diterima. Paket workspace langsung aktif.`" @close="marking = false">
      <form id="paid-form" class="grid gap-4" @submit.prevent="markPaid">
        <AppField label="Metode"><AppInput v-model="paid.method" maxlength="40" required /></AppField>
        <AppField label="Nomor referensi transfer (wajib)" hint="No. transfer / mutasi bank. Satu referensi hanya untuk satu permintaan."><AppInput v-model="paid.reference" maxlength="80" minlength="4" required /></AppField>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="marking = false">Batal</AppButton>
        <AppButton type="submit" form="paid-form" :loading="busy">Ajukan</AppButton>
      </template>
    </AppModal>

    <AppModal v-if="voiding" size="sm" title="Ajukan pembatalan invoice" description="Pelanggan tidak bisa membayar invoice ini lagi. Perlu disetujui staf lain." @close="voiding = false">
      <form id="void-form" class="grid gap-4" @submit.prevent="doVoid">
        <AppField label="Alasan (wajib)"><AppInput v-model="reason" maxlength="200" minlength="5" required /></AppField>
        <AppField label="Nomor tiket / referensi (wajib)"><AppInput v-model="voidRef" maxlength="80" minlength="3" required /></AppField>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="voiding = false">Kembali</AppButton>
        <AppButton type="submit" form="void-form" variant="danger" :loading="busy">Ajukan pembatalan</AppButton>
      </template>
    </AppModal>
  </template>
</template>
