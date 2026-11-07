<script setup>
// Detail pesanan: status & linimasa, item (desain yang diproduksi), pengiriman & resi, bayar ulang / batal sebelum dibayar.
// Setelah kembali dari halaman bayar (?paid=1), status pembayaran ditanyakan ke gateway beberapa saat.
import { onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Clock, QrCode, Receipt, Truck, X } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import OrderItems from '@/components/orders/OrderItems/index.vue'
import OrderTimeline from '@/components/orders/OrderTimeline/index.vue'
import { ordersApi } from '@/api/workspace'
import { useAction, useAsync } from '@/composables/useAsync'
import { rupiah } from '@/lib/format'
import { ORDER_STATUS } from '@/lib/orders'

const route = useRoute()
const router = useRouter()
const id = route.params.id
const { data: o } = useAsync(() => ordersApi.get(id))
const { busy, run } = useAction()
const confirmCancel = ref(false)

let timer = null
let tries = 0
async function check() {
  try { o.value = (await ordersApi.check(id)).data } catch { /* coba lagi di putaran berikutnya */ }
  if (o.value?.status !== 'pending_payment' || ++tries > 30) clearInterval(timer)
}
watch(o, (v) => {
  if (!v || !route.query.paid || v.status !== 'pending_payment' || timer) return
  check()
  timer = setInterval(check, 4000)
}, { immediate: true })
onBeforeUnmount(() => clearInterval(timer))

async function pay() {
  const res = await run(() => ordersApi.pay(id)).catch(() => null)
  if (!res) return
  const u = new URL(res.data.payment_url, window.location.href)
  if (u.origin === window.location.origin) router.push(u.pathname + u.search)
  else window.location.assign(res.data.payment_url)
}
async function cancel() {
  const res = await run(() => ordersApi.cancel(id), 'Pesanan dibatalkan.').catch(() => null)
  confirmCancel.value = false
  if (res) o.value = res.data
}
</script>

<template>
  <AppSkeleton v-if="!o" class="!h-96" />
  <template v-else>
    <PageHeader :title="o.number" back="/orders" back-label="Pesanan">
      <template #meta><AppBadge :tone="ORDER_STATUS[o.status]?.tone">{{ o.status_label }}</AppBadge></template>
      <template v-if="o.status === 'pending_payment'">
        <AppButton variant="ghost" @click="confirmCancel = true"><X class="size-5" /> Batalkan</AppButton>
        <AppButton :loading="busy" @click="pay"><QrCode class="size-5" /> Bayar {{ rupiah(o.total) }} dengan QRIS</AppButton>
      </template>
      <AppButton v-if="o.invoice" variant="secondary" :to="`/billing/invoices/${o.invoice.id}`"><Receipt class="size-5" /> {{ o.status === 'pending_payment' ? 'Invoice' : 'Kuitansi' }}</AppButton>
    </PageHeader>

    <p v-if="route.query.paid && o.status === 'pending_payment'" class="flex items-center gap-2 rounded-md bg-surface-2 px-4 py-3 text-sm" aria-live="polite">
      <Clock class="size-5 animate-pulse text-accent-text" /> Menunggu konfirmasi pembayaran… halaman ini diperbarui otomatis.
    </p>
    <p v-if="o.status === 'cancelled' && o.cancel_reason" class="rounded-md border border-line-strong px-4 py-3 text-sm">Dibatalkan: {{ o.cancel_reason }}</p>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_340px] lg:items-start">
      <AppPanel title="Isi pesanan">
        <div class="grid gap-4">
          <OrderItems :items="o.items" />
          <dl class="grid gap-1 border-t border-line pt-3 text-sm">
            <div class="flex justify-between"><dt class="text-muted">Subtotal</dt><dd class="font-mono">{{ rupiah(o.subtotal) }}</dd></div>
            <div class="flex justify-between"><dt class="text-muted">{{ o.shipping.method ?? 'Pengiriman' }}</dt><dd class="font-mono">{{ o.shipping_fee ? rupiah(o.shipping_fee) : 'Gratis' }}</dd></div>
            <div v-if="o.discount" class="flex justify-between text-ok"><dt>Promo {{ o.promo }}</dt><dd class="font-mono">−{{ rupiah(o.discount) }}</dd></div>
            <div class="flex justify-between text-base font-semibold"><dt>Total</dt><dd class="font-display text-xl">{{ rupiah(o.total) }}</dd></div>
          </dl>
        </div>
      </AppPanel>

      <div class="grid gap-4">
        <AppPanel title="Status"><OrderTimeline :timeline="o.timeline" /></AppPanel>
        <AppPanel title="Pengiriman">
          <div class="grid gap-2 text-sm">
            <p class="flex items-center gap-2 font-medium"><Truck class="size-4" /> {{ o.shipping.method }}<span v-if="o.shipping.eta" class="font-normal text-muted">· {{ o.shipping.eta }}</span></p>
            <template v-if="o.shipping.needs_address">
              <p>{{ o.shipping.recipient_name }} · {{ o.shipping.recipient_phone }}</p>
              <p class="text-muted">{{ o.shipping.address_line }}{{ o.shipping.district ? `, Kec. ${o.shipping.district}` : '' }}, {{ o.shipping.city }}{{ o.shipping.province ? `, ${o.shipping.province}` : '' }} {{ o.shipping.postal_code }}</p>
            </template>
            <p v-if="o.shipping.tracking_number" class="rounded-md bg-surface-2 px-3 py-2">Resi {{ o.shipping.courier }} <span class="font-mono font-semibold">{{ o.shipping.tracking_number }}</span></p>
            <p v-if="o.shipping.notes" class="text-muted">Catatan: {{ o.shipping.notes }}</p>
            <p v-if="['shipped', 'delivered'].includes(o.status)" class="text-muted">Kartu sudah tertaut ke akun Anda — begitu tiba, tempelkan ke ponsel untuk mengaktifkan (tanpa kode).</p>
          </div>
        </AppPanel>
      </div>
    </div>

    <AppModal v-if="confirmCancel" size="sm" title="Batalkan pesanan?" description="Pesanan belum dibayar, jadi tidak ada tagihan yang perlu dikembalikan." @close="confirmCancel = false">
      <template #footer>
        <AppButton variant="secondary" @click="confirmCancel = false">Kembali</AppButton>
        <AppButton variant="danger" :loading="busy" @click="cancel">Batalkan pesanan</AppButton>
      </template>
    </AppModal>
  </template>
</template>
