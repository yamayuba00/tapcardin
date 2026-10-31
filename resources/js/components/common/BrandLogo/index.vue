<script setup>
// DBD §2 — huruf T geometris dalam bingkai + tiga busur sinyal kanan atas; wordmark Syne 700.
defineProps({ wordmark: { type: Boolean, default: true } })
</script>

<template>
  <span class="inline-flex items-center gap-2 font-display text-xl leading-none font-bold tracking-[-.01em] text-fg">
    <svg class="size-7 text-accent-text" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <rect x="2.75" y="2.75" width="18.5" height="18.5" rx="2" />
      <path d="M6.5 9.5h7M10 9.5v8" />
      <path d="M15.5 8.2a1.6 1.6 0 0 1 1.3 1.3" />
      <path d="M15.5 6.4a3.4 3.4 0 0 1 3.1 3.1" />
      <path d="M15.5 4.6a5.2 5.2 0 0 1 4.9 4.9" />
    </svg>
    <span v-if="wordmark">TapCard</span>
  </span>
</template>
