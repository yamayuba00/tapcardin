<script setup>
// Kuitansi kartu (penjualan langsung & perpanjangan masa pakai): bisa dicetak / disimpan PDF dari browser.
import { useRoute } from 'vue-router'
import { Printer } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { receiptsApi } from '@/api/workspace'
import { config, hostContext } from '@/config'
import { useAsync } from '@/composables/useAsync'
import { date, dateTime, rupiah } from '@/lib/format'

const route = useRoute()
const panel = hostContext(undefined, config.baseDomain) === 'admin'
const { data: r, error } = useAsync(() => (panel ? adminApi.receipt(route.params.id) : receiptsApi.get(route.params.id)))
const print = () => window.print()
</script>

<template>
  <div class="print:hidden"><PageHeader eyebrow="Kuitansi" :title="r?.number ?? 'Kuitansi'" :back="panel ? '/' : '/billing'" back-label="Kembali"></PageHeader></div>
  <p v-if="error" class="text-danger" role="alert">{{ error.message }}</p>
  <AppSkeleton v-else-if="!r" class="!h-60" />
  <template v-else>
    <AppPanel :title="r.kind === 'card_sale' ? 'Aktivasi kartu' : 'Perpanjang masa pakai kartu'">
      <dl class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
        <dt class="text-muted">No. kuitansi</dt><dd class="font-mono">{{ r.number }}</dd>
        <dt class="text-muted">Pembeli</dt><dd>{{ r.buyer?.name }} <span class="text-muted">· {{ r.buyer?.email }}</span></dd>
        <dt class="text-muted">Kartu</dt><dd class="font-mono">{{ r.card?.short_code }}</dd>
        <dt class="text-muted">Keterangan</dt><dd>{{ r.item }}</dd>
        <dt class="text-muted">Masa pakai</dt><dd>{{ r.period?.from ? date(r.period.from) : '-' }} sampai {{ r.period?.until ? date(r.period.until) : '-' }}</dd>
        <dt class="text-muted">Jumlah</dt><dd class="font-mono text-base font-semibold">{{ rupiah(r.amount) }}</dd>
        <dt class="text-muted">Pembayaran</dt><dd>{{ r.payment_label ?? '-' }} · {{ r.paid_at ? dateTime(r.paid_at) : 'belum lunas' }}</dd>
        <template v-if="r.seller"><dt class="text-muted">Dijual oleh</dt><dd>{{ r.seller }}</dd></template>
        <template v-if="r.invoice_number"><dt class="text-muted">No. invoice</dt><dd class="font-mono">{{ r.invoice_number }}</dd></template>
      </dl>
    </AppPanel>
    <div class="print:hidden"><AppButton variant="secondary" @click="print"><Printer class="size-5" /> Cetak / PDF</AppButton></div>
  </template>
</template>
