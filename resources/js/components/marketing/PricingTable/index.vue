<script setup>
import { Check } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import { config } from '@/config'

defineProps({ plans: { type: Array, required: true } })
const rupiah = (n) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n)
const perks = (p) => [
  `${p.cards} kartu aktif, masing-masing dengan bio link sendiri`,
  p.connections ? `Hingga ${p.connections} koneksi` : 'Koneksi tanpa batas',
  `Analitik ${p.analytics_days} hari`,
  'Design Studio + 8 template',
  'Tukar Kontak & pipeline',
]
</script>

<template>
  <div class="grid gap-4 md:grid-cols-3">
    <article
      v-for="(p, i) in plans"
      :key="p.key"
      :class="['grid content-start gap-5 rounded-lg border bg-surface p-6', i === 1 ? 'border-accent' : 'border-line']"
    >
      <div class="flex items-center justify-between gap-2">
        <h3 class="font-display text-xl font-bold">{{ p.name }}</h3>
        <span v-if="i === 1" class="rounded-full bg-accent px-3 py-1 text-xs font-semibold text-accent-ink">Paling populer</span>
      </div>
      <p>
        <span class="font-display text-[36px] font-bold">{{ p.price_monthly ? rupiah(p.price_monthly) : 'Gratis' }}</span>
        <span v-if="p.price_monthly" class="text-muted"> /bulan</span>
      </p>
      <ul class="grid gap-2 text-sm">
        <li v-for="perk in perks(p)" :key="perk" class="flex gap-2"><Check class="size-5 flex-none text-ok" aria-hidden="true" /> {{ perk }}</li>
      </ul>
      <AppButton :variant="i === 1 ? 'primary' : 'secondary'" block :href="`${config.appUrl}/login`">{{ p.price_monthly ? 'Coba Pro' : 'Mulai gratis' }}</AppButton>
    </article>

    <article class="grid content-start gap-5 rounded-lg border border-dashed border-line-strong p-6">
      <h3 class="font-display text-xl font-bold">Tim &amp; Perusahaan</h3>
      <p class="text-muted">Subdomain sendiri, kartu untuk setiap anggota tim, branding perusahaan, field terkunci, dan laporan lead per tim.</p>
      <span class="label">Segera hadir</span>
    </article>
  </div>
</template>
