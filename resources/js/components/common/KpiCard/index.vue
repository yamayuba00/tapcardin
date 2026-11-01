<script setup>
// DBD §10.1 — angka mono tabular, label mono kapital; perubahan lewat ikon + teks (bukan hanya warna).
import { ArrowDown, ArrowUp } from 'lucide-vue-next'

defineProps({ label: String, value: [String, Number], hint: String, delta: { type: Number, default: null } })
</script>

<template>
  <div class="grid min-w-0 content-start gap-2 rounded-md border border-line bg-surface p-4 sm:p-5">
    <span class="label !tracking-[.12em] sm:!tracking-[.18em]">{{ label }}</span>
    <span class="num text-2xl leading-none font-medium break-words sm:text-[28px]">{{ value }}</span>
    <span v-if="delta !== null" :class="['inline-flex items-center gap-1 text-sm', delta >= 0 ? 'text-ok' : 'text-danger']">
      <component :is="delta >= 0 ? ArrowUp : ArrowDown" class="size-4" aria-hidden="true" />
      {{ Math.abs(delta) }}% dari minggu lalu
    </span>
    <span v-else-if="hint" class="text-sm text-muted">{{ hint }}</span>
  </div>
</template>
