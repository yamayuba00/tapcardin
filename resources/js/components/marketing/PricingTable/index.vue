<script setup>
// Harga dari GET /api/showcase (tabel `plans`, bisa diubah dari panel): paket personal & paket tim/perusahaan per anggota.
import { computed, ref } from 'vue'
import { Check } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import { config } from '@/config'

const props = defineProps({
  plans: { type: Array, required: true },
  companyPlans: { type: Array, default: () => [] },
  physicalBioPages: { type: Number, default: 3 },
})
const scope = ref('personal')
const rupiah = (n) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n)
const num = (n) => new Intl.NumberFormat('id-ID').format(n)

/** label fitur paket perusahaan (kunci `plans.features`) */
const FEATURE = {
  subdomain: 'Subdomain perusahaan sendiri', teams: 'Tim & pembagian anggota', dynamic_qr: 'QR aman (dinamis) sekali pakai',
  events_checkin: 'Mode Event & check-in peserta', custom_roles: 'Peran & izin kustom', custom_stages: 'Tahap pipeline kustom',
  lead_fields: 'Kolom lead tambahan', auto_assign: 'Bagi lead otomatis ke sales', webhooks: 'Webhook ke sistem Anda', custom_domain: 'Domain kustom (kartu.perusahaan.com)',
}
const personalPerks = (p) => [
  `${p.cards} kartu fisik aktif, tiap kartu sampai ${props.physicalBioPages} bio link`,
  'Kartu digital gratis termasuk',
  p.connections ? `Hingga ${num(p.connections)} koneksi` : 'Koneksi tanpa batas',
  `Riwayat analitik ${p.analytics_days} hari`,
  'Design Studio + 8 template siap cetak',
  'Tukar Kontak & pipeline koneksi',
]
const companyPerks = (p) => [
  p.members ? `Hingga ${num(p.members)} anggota` : 'Anggota tanpa batas',
  `${p.cards_per_member} kartu fisik per anggota`,
  `Riwayat analitik ${p.analytics_days} hari`,
  ...p.features.filter((f) => FEATURE[f]).map((f) => FEATURE[f]),
]
const list = computed(() => (scope.value === 'personal'
  ? props.plans.map((p, i) => ({ ...p, perks: personalPerks(p), highlight: i === 1, cta: p.price_monthly ? `Coba ${p.name}` : 'Mulai gratis', unit: '/bulan' }))
  : props.companyPlans.map((p, i) => ({ ...p, perks: companyPerks(p), highlight: i === 0, cta: p.trial_days ? `Coba gratis ${p.trial_days} hari` : 'Mulai', unit: p.per_seat ? '/anggota/bulan' : '/bulan' }))))
</script>

<template>
  <div class="grid gap-6">
    <AppSegmented v-if="companyPlans.length" v-model="scope" label="Jenis paket" :options="[{ value: 'personal', label: 'Personal' }, { value: 'company', label: 'Tim & perusahaan' }]" class="justify-self-start" />
    <div class="grid gap-4" :class="list.length > 2 ? 'md:grid-cols-3' : 'md:grid-cols-2'">
      <article
        v-for="p in list"
        :key="p.key"
        :class="['grid content-start gap-5 rounded-lg border bg-surface p-6', p.highlight ? 'border-accent' : 'border-line']"
      >
        <div class="flex items-center justify-between gap-2">
          <h3 class="font-display text-xl font-bold">{{ p.name }}</h3>
          <span v-if="p.highlight" class="rounded-full bg-accent px-3 py-1 text-xs font-semibold text-accent-ink">{{ scope === 'personal' ? 'Paling populer' : 'Untuk tim kecil' }}</span>
        </div>
        <p>
          <span class="font-display text-[36px] font-bold">{{ p.price_monthly ? rupiah(p.price_monthly) : 'Gratis' }}</span>
          <span v-if="p.price_monthly" class="text-muted"> {{ p.unit }}</span>
        </p>
        <ul class="grid gap-2 text-sm">
          <li v-for="perk in p.perks" :key="perk" class="flex gap-2"><Check class="size-5 flex-none text-ok" aria-hidden="true" /> {{ perk }}</li>
        </ul>
        <AppButton :variant="p.highlight ? 'primary' : 'secondary'" block :href="`${config.appUrl}/login`">{{ p.cta }}</AppButton>
      </article>
    </div>
    <p class="text-sm text-muted">
      Bayar bulanan atau tahunan (hemat setara 2 bulan) lewat QRIS — semua e-wallet & mobile banking. Batalkan kapan saja; paket berjalan sampai akhir periode.
    </p>
  </div>
</template>
