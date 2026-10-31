<script setup>
// Batang horizontal satu seri (magnitudo per kategori): satu warna --accent, label & angka langsung
// di setiap baris (jadi tidak butuh legenda), skala mulai dari nol, tooltip persentase saat hover/fokus.
import { computed, ref } from 'vue'
import { num } from '@/lib/format'

const props = defineProps({
  items: { type: Array, required: true }, // [{ label, value }]
  label: { type: String, required: true }, // nama grafik untuk pembaca layar
  empty: { type: String, default: 'Belum ada data pada periode ini.' },
})
const max = computed(() => Math.max(1, ...props.items.map((i) => i.value)))
const total = computed(() => props.items.reduce((s, i) => s + i.value, 0))
const hover = ref(null)
const pct = (v) => (total.value ? Math.round((v / total.value) * 100) : 0)
</script>

<template>
  <p v-if="!items.length || !total" class="py-6 text-center text-sm text-muted">{{ empty }}</p>
  <ul v-else class="grid gap-2.5" :aria-label="label">
    <li
      v-for="(it, i) in items"
      :key="it.label"
      tabindex="0"
      class="relative grid gap-1 rounded-sm outline-none focus-visible:outline-2 focus-visible:outline-accent"
      @mouseenter="hover = i" @mouseleave="hover = null" @focus="hover = i" @blur="hover = null"
    >
      <div class="flex items-baseline justify-between gap-3 text-sm">
        <span class="min-w-0 truncate text-fg">{{ it.label }}</span>
        <span class="num flex-none text-muted">{{ num(it.value) }}</span>
      </div>
      <div class="h-2 rounded-full bg-surface-2" aria-hidden="true">
        <div :class="['h-2 rounded-full bg-accent transition-opacity', hover !== null && hover !== i ? 'opacity-40' : '']" :style="{ width: `${Math.max((it.value / max) * 100, it.value ? 2 : 0)}%` }"></div>
      </div>
      <span v-if="hover === i" role="tooltip" class="pointer-events-none absolute -top-8 right-0 z-10 rounded-sm border border-line bg-surface px-2 py-1 text-xs whitespace-nowrap text-fg">
        {{ it.label }}: {{ num(it.value) }} · {{ pct(it.value) }}%
      </span>
    </li>
  </ul>
</template>
