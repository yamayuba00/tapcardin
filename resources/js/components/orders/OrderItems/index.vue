<script setup>
// Item pesanan dengan pratinjau desain yang dibekukan (snapshot) — tampilan inilah yang diproduksi.
import CardSide from '@/components/studio/CardSide/index.vue'
import LanyardStrip from '@/components/studio/LanyardStrip/index.vue'
import { rupiah } from '@/lib/format'

defineProps({ items: { type: Array, required: true }, qr: { type: Object, default: null } })
</script>

<template>
  <ul class="grid gap-4">
    <li v-for="it in items" :key="it.id" class="grid gap-4 border-b border-line pb-4 last:border-b-0 last:pb-0 sm:grid-cols-[180px_minmax(0,1fr)] sm:items-center">
      <div v-if="it.kind === 'card'" class="grid grid-cols-2 gap-1.5" :class="it.design.orientation === 'portrait' ? 'max-w-[140px]' : ''">
        <div class="overflow-hidden rounded-sm"><CardSide :side="it.design.front" :vars="it.design.vars" :kit="it.design.kit" :qr="qr" trim /></div>
        <div class="overflow-hidden rounded-sm"><CardSide :side="it.design.back" :vars="it.design.vars" :kit="it.design.kit" :qr="qr" trim /></div>
      </div>
      <div v-else class="overflow-hidden rounded-sm"><LanyardStrip :side="it.design.front" :vars="it.design.vars" :kit="it.design.kit" :length="180" :scale="1" /></div>
      <div class="grid gap-1">
        <span class="font-medium">{{ it.label }}</span>
        <span class="text-sm text-muted">{{ it.quantity }} pcs × {{ rupiah(it.unit_price) }}</span>
        <span class="font-mono">{{ rupiah(it.total) }}</span>
      </div>
    </li>
  </ul>
</template>
