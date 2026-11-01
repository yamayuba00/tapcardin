<script setup>
// DBD §11 Card UI — --surface-1 + garis --line, radius md, padding 20.
defineProps({ title: String, flush: Boolean, as: { type: String, default: 'section' } })
</script>

<template>
  <component :is="as" :class="['min-w-0 rounded-md border border-line bg-surface', flush ? 'overflow-hidden' : 'p-4 sm:p-5']">
    <!-- Ponsel: judul dan tombol aksi boleh turun ke baris berikutnya, jangan saling menjepit -->
    <div v-if="title || $slots.actions" :class="['mb-4 flex flex-wrap items-center justify-between gap-x-3 gap-y-2', flush ? 'px-4 pt-4 sm:px-5 sm:pt-5' : '']">
      <h2 class="min-w-0 font-sans text-lg font-semibold">{{ title }}</h2>
      <div class="flex flex-wrap items-center gap-2"><slot name="actions" /></div>
    </div>
    <slot />
  </component>
</template>
