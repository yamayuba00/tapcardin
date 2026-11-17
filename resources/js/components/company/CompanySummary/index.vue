<script setup>
// Ringkasan perusahaan (owner/admin): kartu aktif dari batas paket, karyawan, masa aktif, tap & lead 30 hari.
import { computed } from 'vue'
import { Gem, Users } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppMeter from '@/components/ui/AppMeter/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import KpiCard from '@/components/common/KpiCard/index.vue'
import { companyApi } from '@/api/workspace'
import { useAsync } from '@/composables/useAsync'
import { date, num } from '@/lib/format'

const { data } = useAsync(() => companyApi.summary())
const SUB = { trialing: 'Uji coba sampai', active: 'Aktif sampai', past_due: 'Masa tenggang, berakhir', expired: 'Masa aktif habis sejak' }
const until = computed(() => {
  const s = data.value?.subscription
  return s ? `${SUB[s.status] ?? 'Aktif sampai'} ${date(s.active_until)}` : 'Belum berlangganan'
})
</script>

<template>
  <AppSkeleton v-if="!data" class="!h-40" />
  <AppPanel v-else :title="`Ringkasan perusahaan · ${data.plan ?? ''}`">
    <template #actions>
      <AppButton variant="ghost" to="/members"><Users class="size-4" /> Karyawan</AppButton>
      <AppButton variant="ghost" to="/billing"><Gem class="size-4" /> Perpanjang</AppButton>
    </template>
    <div class="grid gap-4">
      <p class="text-sm text-muted">{{ until }}</p>
      <AppMeter label="Kartu aktif" :value="data.cards_active" :max="data.cards_max" />
      <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <KpiCard label="Karyawan" :value="num(data.members)" />
        <KpiCard label="Kartu di stok" :value="num(data.cards_pool)" hint="Belum ditugaskan" />
        <KpiCard label="Tap 30 hari" :value="num(data.taps_30)" />
        <KpiCard label="Lead 30 hari" :value="num(data.leads_30)" />
      </div>
    </div>
  </AppPanel>
</template>
