<script setup>
// DBD §11 Toggle — aktif --accent, target sentuh 44 px.
const model = defineModel({ type: Boolean, default: false })
defineProps({ label: String })
</script>

<template>
  <label class="flex min-h-11 cursor-pointer items-center gap-3 text-sm">
    <span class="relative inline-flex">
      <input v-model="model" type="checkbox" class="peer sr-only" />
      <span class="h-[26px] w-11 rounded-full border border-line-strong bg-surface-2 transition peer-checked:border-accent peer-checked:bg-accent"></span>
      <span class="absolute top-1 left-1 size-[18px] rounded-full bg-muted transition peer-checked:translate-x-[18px] peer-checked:bg-accent-ink"></span>
    </span>
    <span v-if="label || $slots.default"><slot>{{ label }}</slot></span>
  </label>
</template>
