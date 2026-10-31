<script setup>
// Halaman bayar SIMULASI (TAPCARD_PAYMENT_DRIVER=simulator, hanya lokal/test): meniru halaman gateway agar alur
// upgrade bisa dicoba tanpa uang sungguhan. "Bayar" mengirim event yang diproses sama persis seperti webhook asli.
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { FlaskConical, Landmark, QrCode, ShieldCheck, Wallet } from 'lucide-vue-next'
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
const method = ref('BCA Virtual Account')
const METHODS = [
  { value: 'BCA Virtual Account', icon: Landmark, hint: 'Transfer dari m-BCA / KlikBCA / ATM' },
  { value: 'QRIS', icon: QrCode, hint: 'Scan dari aplikasi e-wallet atau m-banking' },
  { value: 'GoPay', icon: Wallet, hint: 'Bayar di aplikasi Gojek' },
]
const rupiah = (n) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n ?? 0)

async function submit(result) {
  const res = await run(() => billingApi.simulate(id, result, method.value)).catch(() => null)
  if (!res) return
  if (result === 'paid') router.replace(`/billing/invoices/${id}?paid=1`)
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
        <fieldset class="grid gap-2">
          <legend class="label mb-2">Pilih metode</legend>
          <label v-for="m in METHODS" :key="m.value" :class="['flex cursor-pointer items-center gap-3 rounded-md border p-3', method === m.value ? 'border-accent bg-surface-2' : 'border-line hover:bg-surface-2']">
            <input v-model="method" type="radio" :value="m.value" class="sr-only" />
            <component :is="m.icon" class="size-5 text-accent-text" />
            <span class="grid"><span class="text-sm font-medium">{{ m.value }}</span><span class="text-xs text-muted">{{ m.hint }}</span></span>
          </label>
        </fieldset>
        <AppButton size="lg" block :loading="busy" @click="submit('paid')">Bayar {{ rupiah(inv.total) }}</AppButton>
        <AppButton variant="ghost" block :disabled="busy" @click="submit('failed')">Simulasikan pembayaran gagal</AppButton>
      </template>
      <p v-else class="text-center text-muted">Tagihan ini sudah {{ inv.status === 'paid' ? 'lunas' : 'tidak berlaku' }}.</p>
      <AppButton variant="secondary" block :to="`/billing/invoices/${id}`">Kembali ke invoice</AppButton>
      <p class="flex items-center justify-center gap-1 text-xs text-muted"><ShieldCheck class="size-4" /> Di produksi halaman ini diganti Midtrans Snap.</p>
    </section>
  </div>
</template>
