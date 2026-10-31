<script setup>
// DBD §11 Card UI — --surface-1 + garis --line, radius md, padding 20.
defineProps({ title: String, flush: Boolean, as: { type: String, default: 'section' } })
</script>

<template>
  <component :is="as" :class="['rounded-md border border-line bg-surface', flush ? 'overflow-hidden' : 'p-5']">
    <div v-if="title || $slots.actions" :class="['mb-4 flex items-center justify-between gap-3', flush ? 'px-5 pt-5' : '']">
      <h2 class="font-sans text-lg font-semibold">{{ title }}</h2>
      <div class="flex items-center gap-2"><slot name="actions" /></div>
    </div>
    <slot />
  </component>
</template>
