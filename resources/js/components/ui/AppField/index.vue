<script setup>
// Label + kontrol + hint/error. Kontrol diletakkan di slot default.
import { CircleAlert } from 'lucide-vue-next'

defineProps({ label: String, hint: String, error: String, srOnly: Boolean })
</script>

<template>
  <label class="grid content-start gap-2">
    <span v-if="label" :class="srOnly ? 'sr-only' : 'text-sm font-medium text-muted'">{{ label }}</span>
    <slot />
    <span v-if="error" class="flex items-center gap-1 text-sm text-danger"><CircleAlert class="size-4" aria-hidden="true" />{{ error }}</span>
    <span v-else-if="hint" class="text-sm text-muted">{{ hint }}</span>
  </label>
</template>
