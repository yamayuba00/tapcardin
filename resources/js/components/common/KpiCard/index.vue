<script setup>
// DBD §10.1 — angka mono tabular, label mono kapital; perubahan lewat ikon + teks (bukan hanya warna).
import { ArrowDown, ArrowUp } from 'lucide-vue-next'

defineProps({ label: String, value: [String, Number], hint: String, delta: { type: Number, default: null } })
</script>

<template>
  <div class="grid min-w-0 content-start gap-2 rounded-md border border-line bg-surface p-3 sm:p-5">
    <span class="label !text-[10px] !tracking-[.08em] sm:!text-xs sm:!tracking-[.18em]">{{ label }}</span>
    <span class="num text-2xl leading-none font-medium break-words sm:text-[28px]">{{ value }}</span>
    <span v-if="delta !== null" :class="['inline-flex items-start gap-1 text-xs sm:text-sm', delta >= 0 ? 'text-ok' : 'text-danger']">
      <component :is="delta >= 0 ? ArrowUp : ArrowDown" class="mt-px size-4 flex-none" aria-hidden="true" />
      <span>{{ Math.abs(delta) }}% <span class="max-sm:hidden">dari</span><span class="sm:hidden">vs</span> minggu lalu</span>
    </span>
    <span v-else-if="hint" class="text-xs text-muted sm:text-sm">{{ hint }}</span>
  </div>
</template>
