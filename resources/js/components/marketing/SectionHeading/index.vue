<script setup>
defineProps({ eyebrow: String, title: { type: String, required: true }, text: String, center: Boolean })
</script>

<template>
  <div :class="['grid max-w-[640px] gap-3', center ? 'mx-auto justify-items-center text-center' : '']">
    <span v-if="eyebrow" class="label !text-accent-text">{{ eyebrow }}</span>
    <h2 class="font-display text-[32px] leading-[1.1] font-bold tracking-[-.015em] sm:text-[40px]">{{ title }}</h2>
    <p v-if="text" class="text-lg text-muted">{{ text }}</p>
    <slot />
  </div>
</template>
