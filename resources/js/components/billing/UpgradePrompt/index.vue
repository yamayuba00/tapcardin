<script setup>
// Layar fitur berbayar yang belum aktif: jelaskan manfaatnya, tampilkan harga paket yang membukanya (dari server),
// dan arahkan langsung ke checkout. Dipakai mis. QR aman (dinamis) — ganti `feature` untuk fitur lain.
import { computed } from 'vue'
import { ArrowRight, Check, Lock, Sparkles } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import { billingApi } from '@/api/workspace'
import { useAsync } from '@/composables/useAsync'
import { useAuthStore } from '@/stores/auth'

const props = defineProps({
  title: { type: String, required: true },
  text: { type: String, required: true },
  benefits: { type: Array, default: () => [] },
  plan: { type: String, default: 'personal_pro' },
})
const auth = useAuthStore()
const { data: billing } = useAsync(() => billingApi.get())
const target = computed(() => billing.value?.plans.find((p) => p.key === props.plan))
const rupiah = (n) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n ?? 0)
const canBuy = computed(() => auth.can('billing.manage'))
// pola QR dekoratif (bukan QR sungguhan) untuk ilustrasi terkunci
const cells = Array.from({ length: 81 }, (_, i) => ((i * 37 + (i % 7) * 11) % 5 < 2 || [0, 1, 2, 9, 11, 18, 19, 20, 6, 7, 8, 15, 17, 24, 25, 26, 54, 55, 56, 63, 65, 72, 73, 74].includes(i)))
</script>

<template>
  <section class="mx-auto grid w-full max-w-[520px] gap-6 rounded-lg border border-line bg-surface p-6 sm:p-8">
    <div class="relative mx-auto grid size-40 place-items-center">
      <svg viewBox="0 0 9 9" class="size-32 text-line-strong" shape-rendering="crispEdges" aria-hidden="true">
        <rect v-for="(on, i) in cells" v-show="on" :key="i" :x="i % 9" :y="Math.floor(i / 9)" width="1" height="1" class="fill-current" />
      </svg>
      <span class="absolute inset-0 grid place-items-center">
        <span class="grid size-16 place-items-center rounded-full border border-accent bg-bg text-accent-text"><Lock class="size-7" /></span>
      </span>
    </div>

    <div class="grid gap-2 text-center">
      <span class="label mx-auto inline-flex items-center gap-1 !text-accent-text"><Sparkles class="size-4" /> Fitur {{ target?.name ?? 'Pro' }}</span>
      <h1 class="text-[28px]">{{ title }}</h1>
      <p class="text-muted">{{ text }}</p>
    </div>

    <ul v-if="benefits.length" class="grid gap-2.5">
      <li v-for="b in benefits" :key="b" class="flex gap-3 text-sm"><span class="grid size-5 flex-none place-items-center rounded-full bg-solid-ok text-on-solid"><Check class="size-3.5" /></span>{{ b }}</li>
    </ul>

    <AppSkeleton v-if="!billing" class="!h-24" />
    <div v-else-if="target" class="grid gap-3 rounded-md bg-surface-2 p-4 text-center">
      <p>
        <span class="font-display text-[32px] font-bold">{{ rupiah(target.price_monthly) }}</span><span class="text-muted"> /bulan</span>
      </p>
      <p class="text-sm text-muted">atau {{ rupiah(target.price_yearly) }}/tahun — hemat {{ rupiah(target.yearly_saving) }}</p>
      <ul class="grid gap-1 text-left text-sm">
        <li v-for="c in target.compare.filter((x) => x.value)" :key="c.label" class="flex justify-between gap-3 border-t border-line pt-1 first:border-t-0 first:pt-0">
          <span class="text-muted">{{ c.label }}</span><span class="font-medium">{{ c.value === true ? 'Termasuk' : c.value }}</span>
        </li>
      </ul>
    </div>

    <div class="grid gap-2">
      <AppButton v-if="canBuy" size="lg" block :to="{ path: '/billing', query: { plan, cycle: 'monthly' } }">Upgrade ke {{ target?.name ?? 'Pro' }} <ArrowRight class="size-5" /></AppButton>
      <p v-else class="text-center text-sm text-muted">Minta pemilik workspace meng-upgrade paket.</p>
      <AppButton variant="ghost" block to="/billing">Bandingkan semua paket</AppButton>
    </div>
  </section>
</template>
