<script setup>
// Harga dari GET /api/showcase (tabel `plans`, bisa diubah dari panel): paket personal & paket perusahaan per 6 bulan.
import { computed, ref } from 'vue'
import { Check, Clock, MessageCircle } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import { config } from '@/config'
import { salesWhatsAppUrl } from '@/lib/salesWhatsApp'
import { track } from '@/lib/track'

const props = defineProps({
  plans: { type: Array, required: true },
  companyPlans: { type: Array, default: () => [] },
  physicalBioPages: { type: Number, default: 3 },
  templateCount: { type: Number, default: null },
  salesWhatsapp: { type: String, default: null }, // nomor sales; kosong → tanpa tautan WhatsApp
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
  props.templateCount ? `Design Studio + ${props.templateCount} template siap cetak` : 'Design Studio + template siap cetak',
  'Tukar Kontak & pipeline koneksi',
]
/** fitur yang dijanjikan paket tetapi belum dibangun — tetap tampil, berlabel "Segera hadir" */
const SOON = ['teams', 'custom_roles', 'custom_stages', 'lead_fields', 'auto_assign']
const companyPerks = (p) => [
  p.cards ? `Maksimal ${num(p.cards)} kartu fisik aktif` : 'Kartu fisik tanpa batas',
  p.members ? `Hingga ${num(p.members)} anggota` : 'Anggota tanpa batas',
  `Riwayat analitik ${p.analytics_days} hari`,
  // fitur yang sudah ada dulu, yang segera hadir di akhir daftar
  ...p.features.filter((f) => FEATURE[f] && !SOON.includes(f)).map((f) => FEATURE[f]),
  ...p.features.filter((f) => FEATURE[f] && SOON.includes(f)).map((f) => ({ text: FEATURE[f], soon: true })),
]
const salesUrl = (plan) => salesWhatsAppUrl(props.salesWhatsapp, 'pricing', plan)
const list = computed(() => (scope.value === 'personal'
  ? props.plans.map((p, i) => ({ ...p, price: p.price_monthly, perks: personalPerks(p), highlight: i === 1, cta: p.price_monthly ? `Coba ${p.name}` : 'Mulai gratis', unit: '/bulan' }))
  : props.companyPlans.map((p, i) => ({ ...p, price: p.price_semiannual, perks: companyPerks(p), highlight: i === 0, cta: p.trial_days ? `Coba gratis ${p.trial_days} hari` : 'Mulai', unit: '/6 bulan' }))))
</script>

<template>
  <div class="grid gap-6">
    <AppSegmented v-if="companyPlans.length" v-model="scope" label="Jenis paket" :options="[{ value: 'personal', label: 'Personal' }, { value: 'company', label: 'Tim & perusahaan' }]" class="justify-self-start" />
    <div class="grid gap-4" :class="list.length > 2 ? 'md:grid-cols-3' : 'md:grid-cols-2'">
      <article
        v-for="(p, i) in list"
        :key="p.key" v-reveal
        :class="[`st-${i}`, 'lift-card relative grid grid-rows-[auto_auto_1fr_auto] gap-5 rounded-lg border bg-surface p-6', p.highlight ? 'border-accent border-t-4' : 'border-line']"
      >
        <div class="flex items-center justify-between gap-2">
          <h3 class="font-display text-xl font-bold">{{ p.name }}</h3>
          <span v-if="p.highlight" class="rounded-full bg-accent px-3 py-1 text-xs font-semibold text-accent-ink">{{ scope === 'personal' ? 'Paling populer' : 'Untuk tim kecil' }}</span>
        </div>
        <p>
          <span class="font-display text-[36px] font-bold">{{ p.price ? rupiah(p.price) : 'Gratis' }}</span>
          <span v-if="p.price" class="text-muted"> {{ p.unit }}</span>
        </p>
        <ul class="grid gap-2 text-sm">
          <li v-for="perk in p.perks" :key="perk.text ?? perk" :class="['flex gap-2', perk.soon ? 'text-muted' : '']">
            <component :is="perk.soon ? Clock : Check" :class="['size-5 flex-none', perk.soon ? 'text-muted' : 'text-ok']" aria-hidden="true" />
            <span>{{ perk.text ?? perk }}<span v-if="perk.soon" class="ml-1.5 rounded-full bg-surface-2 px-2 py-0.5 text-xs font-medium whitespace-nowrap text-info">Segera hadir</span></span>
          </li>
        </ul>
        <div class="grid gap-2 self-end">
          <AppButton
            :variant="p.highlight ? 'primary' : 'secondary'" block :href="`${config.appUrl}/login`"
            data-event="cta_click" :data-location="`pricing_${p.key}`" @click="track('cta_click', { location: 'pricing', plan: p.key })"
          >{{ p.cta }}</AppButton>
          <a
            v-if="scope === 'company' && salesUrl(p.name)" :href="salesUrl(p.name)" target="_blank" rel="noopener"
            class="inline-flex min-h-11 items-center justify-center gap-2 text-sm font-semibold text-accent-text"
            data-event="whatsapp_click" :data-location="`pricing_${p.key}`" @click="track('whatsapp_click', { location: 'pricing', plan: p.key })"
          ><MessageCircle class="size-4 text-ok" aria-hidden="true" /> Tanya Sales soal {{ p.name }}</a>
        </div>
      </article>
    </div>
    <p v-if="scope === 'personal'" class="text-sm text-muted">
      Bayar bulanan atau tahunan (hemat setara 2 bulan) lewat QRIS — semua e-wallet & mobile banking. Batalkan kapan saja; paket berjalan sampai akhir periode.
    </p>
    <p v-else class="text-sm text-muted">
      Langganan workspace & subdomain per 6 bulan lewat QRIS. Kartu fisik dipesan terpisah sesuai kebutuhan, sampai batas paket.
    </p>
  </div>
</template>
