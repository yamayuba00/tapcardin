<script setup>
// Halaman bayar SIMULASI (PAYMENT_DRIVER=simulator, hanya lokal/test): meniru halaman gateway agar alur
// upgrade bisa dicoba tanpa uang sungguhan. "Bayar" mengirim event yang diproses sama persis seperti webhook asli.
// Hitung mundur ke batas tagihan (jam server) + cek status otomatis; lunas → halaman tujuan dengan ?paid=1.
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CircleAlert, FlaskConical, QrCode, ShieldCheck, TimerOff } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import BrandLogo from '@/components/common/BrandLogo/index.vue'
import { billingApi } from '@/api/workspace'
import { useAction, useAsync } from '@/composables/useAsync'
import { usePaymentWatch } from '@/composables/usePaymentWatch'
import { clock } from '@/lib/countdown'

const route = useRoute()
const router = useRouter()
const id = route.params.id
const { data: inv } = useAsync(() => billingApi.invoice(id))
const { busy, run } = useAction()
const { left, state } = usePaymentWatch(inv)
const failed = ref(false)
// pembayaran hanya QRIS (sama dengan gateway produksi: PAYMENT_METHOD=QRIS)
const method = 'QRIS'
const rupiah = (n) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n ?? 0)

// lunas (dari tombol ini, tab lain, atau webhook) → halaman tujuan
watch(state, (s) => { if (s === 'paid') router.replace(`${inv.value?.return_to ?? `/billing/invoices/${id}`}?paid=1`) })

async function submit(result) {
  failed.value = false
  const res = await run(() => billingApi.simulate(id, result, method)).catch(() => null)
  if (!res) return
  inv.value = { ...inv.value, ...res.data }
  if (result === 'failed') failed.value = true
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

      <template v-if="state === 'pending'">
        <p
          v-if="left !== null" aria-live="polite"
          :class="['flex items-center justify-center gap-2 rounded-md px-3 py-2 text-sm', left < 300 ? 'bg-surface-2 font-medium text-warn' : 'bg-surface-2 text-muted']"
        >Selesaikan pembayaran dalam <span class="font-mono text-base text-fg">{{ clock(left) }}</span></p>
        <p v-if="failed" class="flex items-start gap-2 rounded-md border border-danger px-3 py-2 text-sm text-danger" role="alert">
          <CircleAlert class="mt-0.5 size-4 flex-none" /> Pembayaran gagal. Tagihan masih terbuka — silakan coba lagi.
        </p>
        <div class="grid justify-items-center gap-3 rounded-md border border-line p-4 text-center">
          <span class="flex items-center gap-2 text-sm font-semibold"><QrCode class="size-5 text-accent-text" /> QRIS</span>
          <div class="grid size-44 place-items-center rounded-md bg-bone-0 text-ink-950" aria-hidden="true"><QrCode class="size-32" /></div>
          <ol class="grid list-decimal gap-1 pl-5 text-left text-sm text-muted">
            <li>Buka e-wallet (GoPay, OVO, DANA, ShopeePay) atau m-banking.</li>
            <li>Pilih bayar / scan QRIS, arahkan ke kode di atas.</li>
            <li>Pastikan nominal {{ rupiah(inv.total) }}, lalu bayar.</li>
          </ol>
          <p class="text-xs text-muted">Halaman ini memeriksa status otomatis — tidak perlu dimuat ulang.</p>
        </div>
        <AppButton size="lg" block :loading="busy" @click="submit('paid')">Simulasikan sudah bayar {{ rupiah(inv.total) }}</AppButton>
        <AppButton variant="ghost" block :disabled="busy" @click="submit('failed')">Simulasikan pembayaran gagal</AppButton>
      </template>
      <div v-else-if="state === 'expired' || state === 'void'" class="grid justify-items-center gap-3 text-center">
        <TimerOff class="size-10 text-warn" aria-hidden="true" />
        <p class="font-semibold">{{ state === 'expired' ? 'Waktu pembayaran habis' : 'Tagihan ini sudah tidak berlaku' }}</p>
        <p class="text-sm text-muted">Tidak ada uang yang ditarik. Buat tagihan baru untuk melanjutkan.</p>
        <AppButton block :to="inv.renew_to ?? '/billing'">Buat tagihan baru</AppButton>
      </div>
      <AppButton variant="secondary" block :to="`/billing/invoices/${id}`">Kembali ke invoice</AppButton>
      <p class="flex items-center justify-center gap-1 text-xs text-muted"><ShieldCheck class="size-4" /> Di produksi QRIS dibuat gateway dengan nominal pas dan lunas otomatis.</p>
    </section>
  </div>
</template>
