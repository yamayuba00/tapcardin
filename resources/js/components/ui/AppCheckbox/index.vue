<script setup>
const model = defineModel({ type: Boolean, default: false })
defineProps({ label: String })
</script>

<template>
  <label class="flex min-h-11 cursor-pointer items-start gap-3 text-sm">
    <input v-model="model" type="checkbox" class="mt-0.5 size-5 flex-none accent-accent" />
    <span><slot>{{ label }}</slot></span>
  </label>
</template>
