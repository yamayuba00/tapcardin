<script setup>
// Render kartu fisik template Onyx (DBD §8.4): latar onyx-950, bilah champagne kiri, ikon tap kanan atas.
import { Nfc } from 'lucide-vue-next'

defineProps({ name: String, label: String, small: Boolean }) // small = thumbnail di daftar
</script>

<template>
  <div :class="['relative flex aspect-[85.6/54] flex-col justify-end overflow-hidden border border-line bg-onyx-950 p-[7%] text-bone-50', small ? 'rounded-[6px]' : 'rounded-[10px]']">
    <span class="absolute inset-y-0 left-0 w-[2%] bg-champagne-500"></span>
    <Nfc :class="['absolute top-[10%] right-[7%] text-champagne-500', small ? 'size-4' : 'size-6']" aria-hidden="true" />
    <span :class="['truncate font-display leading-none font-bold', small ? 'text-sm' : 'text-xl']">{{ name || 'Kartu TapCard' }}</span>
    <span :class="['mt-1.5 truncate font-mono tracking-[.16em] text-champagne-500 uppercase', small ? 'text-[8px]' : 'text-[10px]']">{{ label }}</span>
  </div>
</template>
