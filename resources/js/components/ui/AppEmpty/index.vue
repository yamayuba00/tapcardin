<script setup>
// DBD §11 Empty state — cincin sinyal + 1 kalimat + 1 tombol.
import SignalRings from '@/components/common/SignalRings/index.vue'

defineProps({ text: String })
</script>

<template>
  <div class="grid justify-items-center gap-4 px-4 py-12 text-center">
    <SignalRings class="size-24 text-line-strong" />
    <p class="max-w-sm text-muted">{{ text }}</p>
    <slot />
  </div>
</template>
