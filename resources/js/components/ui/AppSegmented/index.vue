<script setup>
// DBD §11 Scope picker / segmented — terpilih --accent / --accent-ink.
const model = defineModel({ type: [String, Number], required: true })
defineProps({ options: { type: Array, required: true }, label: String })
</script>

<template>
  <div class="inline-flex gap-[3px] rounded-md bg-surface-2 p-[3px]" role="group" :aria-label="label">
    <button
      v-for="o in options"
      :key="o.value"
      type="button"
      :aria-pressed="model === o.value"
      class="cursor-pointer min-h-[38px] rounded-lg px-4 text-sm font-medium text-muted aria-pressed:bg-accent aria-pressed:text-accent-ink"
      @click="model = o.value"
    >
      {{ o.label }}
    </button>
  </div>
</template>
