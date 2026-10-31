<script setup>
import { ChevronDown } from 'lucide-vue-next'

defineProps({ items: { type: Array, required: true } }) // [{ q, a }]
</script>

<template>
  <div class="divide-y divide-line border-y border-line">
    <details v-for="(it, i) in items" :key="i" class="group">
      <summary class="flex min-h-16 cursor-pointer list-none items-center justify-between gap-4 py-4 text-lg font-medium [&::-webkit-details-marker]:hidden">
        {{ it.q }}
        <ChevronDown class="size-5 flex-none text-muted transition-transform group-open:rotate-180" aria-hidden="true" />
      </summary>
      <p class="pb-5 text-muted">{{ it.a }}</p>
    </details>
  </div>
</template>
