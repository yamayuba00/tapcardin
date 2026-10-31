<script setup>
// Linimasa pesanan: dibuat → dibayar → produksi → dikirim → diterima (atau dibatalkan).
import { computed } from 'vue'
import { Check, X } from 'lucide-vue-next'
import { dateTime } from '@/lib/format'
import { ORDER_STEPS } from '@/lib/orders'

const props = defineProps({ timeline: { type: Array, required: true } })
const cancelled = computed(() => props.timeline.find((t) => t.key === 'cancelled' && t.at))
const steps = computed(() => {
  const list = props.timeline.filter((t) => ORDER_STEPS.includes(t.key))
  return cancelled.value ? [...list.filter((t) => t.at), cancelled.value] : list
})
</script>

<template>
  <ol class="grid gap-0">
    <li v-for="(s, i) in steps" :key="s.key" class="relative grid grid-cols-[28px_minmax(0,1fr)] gap-3 pb-5 last:pb-0">
      <span v-if="i < steps.length - 1" class="absolute top-7 bottom-0 left-[13px] w-px" :class="s.at ? 'bg-ok' : 'bg-line'" aria-hidden="true"></span>
      <span
        class="relative grid size-7 place-items-center rounded-full text-xs"
        :class="s.key === 'cancelled' ? 'bg-solid-danger text-on-solid' : s.at ? 'bg-solid-ok text-on-solid' : 'border border-line-strong text-muted'"
      >
        <X v-if="s.key === 'cancelled'" class="size-4" /><Check v-else-if="s.at" class="size-4" /><span v-else class="num">{{ i + 1 }}</span>
      </span>
      <div class="grid pt-0.5">
        <span class="text-sm font-medium" :class="s.at ? 'text-fg' : 'text-muted'">{{ s.label }}</span>
        <span v-if="s.at" class="text-xs text-muted">{{ dateTime(s.at) }}</span>
      </div>
    </li>
  </ol>
</template>
