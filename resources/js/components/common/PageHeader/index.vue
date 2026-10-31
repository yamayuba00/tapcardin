<script setup>
import { ArrowLeft } from 'lucide-vue-next'

defineProps({ title: String, eyebrow: String, back: { type: [String, Object], default: null }, backLabel: String })
</script>

<template>
  <header class="flex flex-wrap items-end justify-between gap-3">
    <div class="grid gap-1">
      <RouterLink v-if="back" :to="back" class="inline-flex items-center gap-1 text-sm no-underline hover:underline">
        <ArrowLeft class="size-4" aria-hidden="true" /> {{ backLabel }}
      </RouterLink>
      <span v-else-if="eyebrow" class="label">{{ eyebrow }}</span>
      <h1 class="break-words">{{ title }}</h1>
      <slot name="meta" />
    </div>
    <div class="flex flex-wrap items-center gap-2"><slot /></div>
  </header>
</template>
