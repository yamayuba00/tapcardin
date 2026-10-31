<script setup>
// Render kartu fisik template Onyx (DBD §8.4): latar onyx-950, bilah champagne kiri, ikon tap kanan atas.
import { Nfc } from 'lucide-vue-next'

defineProps({ name: String, label: String })
</script>

<template>
  <div class="relative flex aspect-[85.6/54] flex-col justify-end overflow-hidden rounded-[10px] border border-line bg-onyx-950 p-[7%] text-bone-50">
    <span class="absolute inset-y-0 left-0 w-[2%] bg-champagne-500"></span>
    <Nfc class="absolute top-[10%] right-[7%] size-6 text-champagne-500" aria-hidden="true" />
    <span class="font-display text-xl leading-none font-bold">{{ name || 'Kartu TapCard' }}</span>
    <span class="mt-1.5 font-mono text-[10px] tracking-[.16em] text-champagne-500 uppercase">{{ label }}</span>
  </div>
</template>
