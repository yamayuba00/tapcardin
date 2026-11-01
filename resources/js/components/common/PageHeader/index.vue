<script setup>
import { ArrowLeft } from 'lucide-vue-next'

defineProps({ title: String, eyebrow: String, back: { type: [String, Object], default: null }, backLabel: String })
</script>

<template>
  <!-- Ponsel: judul di atas, lalu baris tombol aksi di bawahnya (membungkus, tidak melebihi layar). Desktop: berdampingan. -->
  <header class="grid gap-3 sm:flex sm:flex-wrap sm:items-end sm:justify-between">
    <div class="grid min-w-0 gap-1">
      <RouterLink v-if="back" :to="back" class="inline-flex min-h-9 items-center gap-1 text-sm no-underline hover:underline">
        <ArrowLeft class="size-4" aria-hidden="true" /> {{ backLabel }}
      </RouterLink>
      <span v-else-if="eyebrow" class="label">{{ eyebrow }}</span>
      <h1 class="text-[26px] break-words sm:text-[32px]">{{ title }}</h1>
      <slot name="meta" />
    </div>
    <div class="flex flex-wrap items-center gap-2"><slot /></div>
  </header>
</template>
