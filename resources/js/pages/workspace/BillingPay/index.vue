<script setup>
// Halaman bayar SIMULASI (PAYMENT_DRIVER=simulator, hanya lokal/test): meniru halaman gateway agar alur
// upgrade bisa dicoba tanpa uang sungguhan. "Bayar" mengirim event yang diproses sama persis seperti webhook asli.
import { useRoute, useRouter } from 'vue-router'
import { FlaskConical, QrCode, ShieldCheck } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import BrandLogo from '@/components/common/BrandLogo/index.vue'
import { billingApi } from '@/api/workspace'
import { useAction, useAsync } from '@/composables/useAsync'
import { useToastStore } from '@/stores/toast'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()
const id = route.params.id
const { data: inv } = useAsync(() => billingApi.invoice(id))
const { busy, run } = useAction()
// pembayaran hanya QRIS (sama dengan gateway produksi: PAYMENT_METHOD=QRIS)
const method = 'QRIS'
const rupiah = (n) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n ?? 0)

async function submit(result) {
  const res = await run(() => billingApi.simulate(id, result, method)).catch(() => null)
  if (!res) return
  // langganan → halaman invoice; pesanan kartu → halaman pesanan
  if (result === 'paid') router.replace(`${inv.value?.return_to ?? `/billing/invoices/${id}`}?paid=1`)
  else toast.push('Pembayaran gagal (simulasi). Tagihan masih terbuka — coba metode lain.')
}
</script>

<template>
  <div class="grid min-h-dvh place-items-center bg-surface-2 p-4">
    <AppSkeleton v-if="!inv" class="!h-96 w-full max-w-md" />
    <section v-else class="grid w-full max-w-md gap-5 rounded-lg border border-line bg-surface p-6">
      <p class="flex items-center gap-2 rounded-md border border-warn px-3 py-2 text-sm text-warn"><FlaskConical class="size-5" /> Mode simulasi — tidak ada uang yang ditarik.</p>
      <div class="flex items-center justify-between gap-3"><BrandLogo /><span class="font-mono text-xs text-muted">{{ inv.number }}</span></div>
      <div class="grid gap-1 text-center">
        <span class="text-sm text-muted">{{ inv.lines?.[0]?.label }}</span>
        <span class="font-display text-[36px] font-bold">{{ rupiah(inv.total) }}</span>
      </div>
      <template v-if="inv.status === 'issued' || inv.status === 'overdue'">
        <div class="grid justify-items-center gap-3 rounded-md border border-line p-4 text-center">
          <span class="flex items-center gap-2 text-sm font-semibold"><QrCode class="size-5 text-accent-text" /> QRIS</span>
          <div class="grid size-44 place-items-center rounded-md bg-bone-0 text-ink-950" aria-hidden="true"><QrCode class="size-32" /></div>
          <ol class="grid list-decimal gap-1 pl-5 text-left text-sm text-muted">
            <li>Buka e-wallet (GoPay, OVO, DANA, ShopeePay) atau m-banking.</li>
            <li>Pilih bayar / scan QRIS, arahkan ke kode di atas.</li>
            <li>Pastikan nominal {{ rupiah(inv.total) }}, lalu bayar.</li>
          </ol>
        </div>
        <AppButton size="lg" block :loading="busy" @click="submit('paid')">Simulasikan sudah bayar {{ rupiah(inv.total) }}</AppButton>
        <AppButton variant="ghost" block :disabled="busy" @click="submit('failed')">Simulasikan pembayaran gagal</AppButton>
      </template>
      <p v-else class="text-center text-muted">Tagihan ini sudah {{ inv.status === 'paid' ? 'lunas' : 'tidak berlaku' }}.</p>
      <AppButton variant="secondary" block :to="`/billing/invoices/${id}`">Kembali ke invoice</AppButton>
      <p class="flex items-center justify-center gap-1 text-xs text-muted"><ShieldCheck class="size-4" /> Di produksi QRIS dibuat gateway dengan nominal pas dan lunas otomatis.</p>
    </section>
  </div>
</template>
