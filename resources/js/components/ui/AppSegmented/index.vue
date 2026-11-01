<script setup>
// DBD §11 Scope picker / segmented — terpilih --accent / --accent-ink.
const model = defineModel({ type: [String, Number], required: true })
defineProps({ options: { type: Array, required: true }, label: String })
</script>

<template>
  <!-- Ponsel: pilihan yang banyak membungkus ke baris berikutnya (bukan terpotong / menggeser halaman) -->
  <div class="inline-flex max-w-full flex-wrap gap-[3px] rounded-md bg-surface-2 p-[3px]" role="group" :aria-label="label">
    <button
      v-for="o in options"
      :key="o.value"
      type="button"
      :aria-pressed="model === o.value"
      class="cursor-pointer min-h-[40px] flex-auto rounded-lg px-3 text-sm font-medium text-muted aria-pressed:bg-accent aria-pressed:text-accent-ink sm:flex-none sm:px-4"
      @click="model = o.value"
    >
      {{ o.label }}
    </button>
  </div>
</template>
