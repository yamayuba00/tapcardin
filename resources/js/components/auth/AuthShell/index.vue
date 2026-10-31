<script setup>
// Kerangka halaman masuk / daftar / lupa password di app.: merek, judul, isi formulir, tautan kaki.
import BrandLogo from '@/components/common/BrandLogo/index.vue'
import { config } from '@/config'

defineProps({ title: { type: String, required: true }, subtitle: String })
const home = config.marketingUrl || '/'
</script>

<template>
  <main class="grid min-h-dvh place-items-center px-4 py-8">
    <div class="grid w-full max-w-[420px] gap-6">
      <a :href="home" class="justify-self-start no-underline" aria-label="TapCard"><BrandLogo /></a>
      <div class="grid gap-2">
        <h1>{{ title }}</h1>
        <p v-if="subtitle" class="text-muted">{{ subtitle }}</p>
      </div>
      <slot />
      <div v-if="$slots.footer" class="text-center text-sm text-muted"><slot name="footer" /></div>
    </div>
  </main>
</template>
