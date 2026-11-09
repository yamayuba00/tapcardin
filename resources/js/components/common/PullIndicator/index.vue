<script setup>
// Indikator tarik-untuk-refresh di atas konten (usePullToRefresh). Berputar mengikuti tarikan, lalu berputar terus saat memuat.
import { RefreshCw } from 'lucide-vue-next'

defineProps({ pull: { type: Number, default: 0 }, refreshing: Boolean, threshold: { type: Number, default: 70 } })
</script>

<template>
  <div
    v-if="pull > 0 || refreshing"
    class="pointer-events-none fixed inset-x-0 top-[calc(8px+env(safe-area-inset-top))] z-40 flex justify-center lg:hidden"
    :style="{ transform: `translateY(${Math.max(0, pull - 40)}px)` }"
    role="status"
    :aria-label="refreshing ? 'Memuat ulang' : pull >= threshold ? 'Lepaskan untuk memuat ulang' : 'Tarik untuk memuat ulang'"
  >
    <span class="grid size-10 place-items-center rounded-full border border-line bg-surface" :class="pull >= threshold || refreshing ? 'text-accent-text' : 'text-muted'">
      <RefreshCw class="size-5" :class="refreshing ? 'animate-spin' : ''" :style="refreshing ? null : { transform: `rotate(${pull * 3}deg)` }" />
    </span>
  </div>
</template>
