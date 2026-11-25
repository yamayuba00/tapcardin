<script setup>
// Invoice / kuitansi: rincian, status, metode bayar; tombol bayar bila masih terbuka; cetak / simpan PDF dari browser.
// Setelah kembali dari gateway, status ditanyakan langsung ke gateway beberapa saat sampai pembayaran terkonfirmasi.
import { computed, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CircleCheck, Clock, Download, House, Printer, TimerOff } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import ActivePeriod from '@/components/billing/ActivePeriod/index.vue'
import BrandLogo from '@/components/common/BrandLogo/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { download } from '@/api/client'
import { billingApi } from '@/api/workspace'
import { useAction, useAsync } from '@/composables/useAsync'
import { usePaymentWatch } from '@/composables/usePaymentWatch'
import { clock } from '@/lib/countdown'
import { useNotificationStore } from '@/stores/notifications'
import { dateTime } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const id = route.params.id
const { data: inv, reload } = useAsync(() => billingApi.invoice(id))
const { busy, run } = useAction()
const toast = useToastStore()
const rupiah = (n) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n ?? 0)
const STATUS = { issued: ['warn', 'Belum dibayar'], overdue: ['danger', 'Terlambat'], paid: ['ok', 'Lunas'], void: ['muted', 'Dibatalkan'], draft: ['muted', 'Draf'] }
const payable = computed(() => state.value === 'pending')

// hitung mundur batas bayar + cek status ke gateway (tiap 4 dtk) setelah kembali dari halaman bayar (?paid)
const { left, state } = usePaymentWatch(inv, () => !!route.query.paid)
// kabar lunas lewat realtime → langsung muat status invoice
const notifications = useNotificationStore()
const unsubscribe = notifications.subscribe((type) => { if (type === 'invoice_paid') reload() })
onBeforeUnmount(unsubscribe)
const justPaid = computed(() => state.value === 'paid' && !!route.query.paid)
// nama workspace hanya ditampilkan bila berbeda dari nama penerima (workspace personal = nama pengguna)
const showTenant = computed(() => inv.value?.tenant && inv.value.tenant !== inv.value.bill_to?.name)

const print = () => window.print()
// PDF dari server (unduhan dengan sesi login)
const pdf = () => download(`/api/billing/invoices/${inv.value.id}/pdf`, `${inv.value.number.replace(/[^A-Za-z0-9._-]+/g, '-')}.pdf`).catch((e) => toast.error(e.message))

async function pay() {
  const res = await run(() => billingApi.pay(id)).catch(() => null)
  if (!res) return
  const u = new URL(res.data.payment_url, window.location.href)
  if (u.origin === window.location.origin) router.push(u.pathname)
  else window.location.assign(res.data.payment_url)
}
</script>

<template>
  <div class="print:hidden"><PageHeader eyebrow="Tagihan" :title="inv?.number ?? 'Invoice'" back="/billing" back-label="Paket & tagihan">
    <AppButton v-if="inv" variant="secondary" @click="pdf"><Download class="size-5" /> Unduh PDF</AppButton>
    <AppButton v-if="inv" variant="secondary" @click="print"><Printer class="size-5" /> Cetak</AppButton>
    <AppButton v-if="payable && auth.can('billing.manage')" :loading="busy" @click="pay">Bayar {{ rupiah(inv.total) }}</AppButton>
  </PageHeader></div>

  <AppSkeleton v-if="!inv" class="!h-96" />
  <!-- baru lunas: ringkasan jelas sebelum rincian kuitansi -->
  <section v-if="inv && justPaid" class="mx-auto grid w-full max-w-[760px] justify-items-center gap-4 rounded-lg border border-ok bg-surface px-6 py-8 text-center print:hidden" aria-live="polite">
    <span class="grid size-14 place-items-center rounded-full bg-surface-2 text-ok"><CircleCheck class="size-8" /></span>
    <div class="grid gap-1">
      <h2 class="text-2xl">Pembayaran berhasil</h2>
      <p class="text-muted">{{ rupiah(inv.total) }}{{ inv.payment_method ? ` via ${inv.payment_method}` : '' }} · {{ dateTime(inv.paid_at) }}</p>
    </div>
    <div v-if="inv.active_period" class="grid w-full max-w-sm justify-items-center gap-2 rounded-md bg-surface-2 px-4 py-3">
      <span class="text-sm">Paket <b>{{ inv.plan }}</b> aktif</span>
      <ActivePeriod :period="inv.active_period" />
    </div>
    <div class="flex flex-wrap justify-center gap-2">
      <AppButton @click="print"><Printer class="size-5" /> Unduh kuitansi (PDF)</AppButton>
      <AppButton variant="secondary" to="/dashboard"><House class="size-5" /> Ke beranda</AppButton>
    </div>
  </section>
  <article v-if="inv" class="mx-auto grid w-full max-w-[760px] gap-8 rounded-lg border border-line bg-surface p-6 sm:p-10 print:border-0 print:p-0">
    <p v-if="route.query.paid && state === 'pending'" class="flex items-center gap-2 rounded-md bg-surface-2 px-4 py-3 text-sm print:hidden" aria-live="polite">
      <Clock class="size-5 animate-pulse text-accent-text" /> Menunggu konfirmasi dari gateway pembayaran… halaman ini diperbarui otomatis.
    </p>
    <p v-else-if="state === 'pending' && left !== null" class="flex flex-wrap items-center gap-2 rounded-md bg-surface-2 px-4 py-3 text-sm print:hidden">
      <Clock class="size-5 text-accent-text" /> Bayar dalam <span :class="['font-mono', left < 300 ? 'text-warn' : 'text-fg']">{{ clock(left) }}</span> — setelah itu tagihan batal otomatis.
    </p>
    <div v-if="state === 'expired' || (state === 'void' && inv.status !== 'draft')" class="flex flex-wrap items-center gap-3 rounded-md border border-warn px-4 py-3 text-sm print:hidden">
      <TimerOff class="size-5 text-warn" /> <span class="flex-1">Tagihan ini sudah tidak berlaku. Tidak ada uang yang ditarik.</span>
      <AppButton v-if="auth.can('billing.manage')" :to="inv.renew_to ?? '/billing'">Buat tagihan baru</AppButton>
    </div>
    <p v-if="inv.status === 'paid' && !justPaid" class="flex items-center gap-2 rounded-md border border-ok px-4 py-3 text-sm print:hidden">
      <CircleCheck class="size-5 text-ok" /> Lunas {{ dateTime(inv.paid_at) }}{{ inv.payment_method ? ` via ${inv.payment_method}` : '' }}.{{ inv.plan ? ` Paket ${inv.plan} aktif.` : '' }}
    </p>

    <header class="flex flex-wrap items-start justify-between gap-6">
      <div class="grid gap-2">
        <BrandLogo />
        <p class="text-sm text-muted">TapCard — kartu nama NFC & bio link</p>
      </div>
      <div class="grid justify-items-end gap-1 text-right">
        <span class="label">{{ inv.status === 'paid' ? 'Kuitansi' : 'Invoice' }}</span>
        <span class="font-mono text-lg">{{ inv.number }}</span>
        <AppBadge :tone="STATUS[inv.status][0]">{{ STATUS[inv.status][1] }}</AppBadge>
      </div>
    </header>

    <dl class="grid gap-6 text-sm sm:grid-cols-3">
      <div class="grid gap-1"><dt class="label">Ditagihkan kepada</dt><dd class="grid">{{ inv.bill_to?.name }}<span class="text-muted">{{ inv.bill_to?.email }}</span><span v-if="showTenant" class="text-muted">{{ inv.tenant }}</span></dd></div>
      <div class="grid gap-1"><dt class="label">Tanggal terbit</dt><dd class="font-mono">{{ dateTime(inv.issued_at) }}</dd></div>
      <div class="grid gap-1"><dt class="label">{{ inv.status === 'paid' ? 'Dibayar' : 'Berlaku sampai' }}</dt><dd class="font-mono">{{ dateTime(inv.status === 'paid' ? inv.paid_at : inv.due_at) }}</dd></div>
    </dl>

    <table class="w-full text-sm">
      <thead><tr class="border-b border-line-strong text-left"><th class="label py-2 font-normal">Deskripsi</th><th class="label py-2 text-right font-normal">Jml</th><th class="label py-2 text-right font-normal">Harga</th></tr></thead>
      <tbody>
        <tr v-for="(l, i) in inv.lines" :key="i" class="border-b border-line">
          <td class="py-3">{{ l.label }}<span v-if="l.note" class="block text-xs text-ok">{{ l.note }}</span></td>
          <td class="py-3 text-right font-mono">{{ l.qty }}</td>
          <td class="py-3 text-right font-mono">{{ rupiah(l.amount) }}</td>
        </tr>
      </tbody>
      <tfoot class="text-right">
        <tr><td colspan="2" class="pt-3 text-muted">Subtotal</td><td class="pt-3 font-mono">{{ rupiah(inv.subtotal) }}</td></tr>
        <tr v-if="inv.discount"><td colspan="2" class="text-muted">Diskon</td><td class="font-mono">−{{ rupiah(inv.discount) }}</td></tr>
        <tr v-if="inv.tax"><td colspan="2" class="text-muted">PPN</td><td class="font-mono">{{ rupiah(inv.tax) }}</td></tr>
        <tr class="text-base font-semibold"><td colspan="2" class="pt-2">Total</td><td class="pt-2 font-display text-xl">{{ rupiah(inv.total) }}</td></tr>
      </tfoot>
    </table>

    <footer class="text-xs text-muted">Pertanyaan tentang tagihan ini? Balas email notifikasi atau hubungi support TapCard dengan menyebut nomor {{ inv.number }}.</footer>
  </article>
</template>
