<script setup>
import { ChevronDown } from 'lucide-vue-next'
import { track } from '@/lib/track'

defineProps({ items: { type: Array, required: true } }) // [{ q, a }]
</script>

<template>
  <div class="divide-y divide-line border-y border-line">
    <details v-for="(it, i) in items" :key="i" class="faq-item group" @toggle="(e) => e.target.open && track('faq_open', { question: it.q })">
      <summary class="flex min-h-16 cursor-pointer list-none items-center justify-between gap-4 py-4 text-lg font-medium [&::-webkit-details-marker]:hidden">
        {{ it.q }}
        <ChevronDown class="size-5 flex-none text-muted transition-transform group-open:rotate-180" aria-hidden="true" />
      </summary>
      <p class="pb-5 text-muted">{{ it.a }}</p>
    </details>
  </div>
</template>
