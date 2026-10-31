<script setup>
// DBD §3.5 — tahap lead selalu titik warna + nama tahap. Warna = nama swatch dari server (bukan hex).
defineProps({ stage: { type: Object, default: null } })

// Kelas statis agar terdeteksi Tailwind.
const dot = {
  sky: 'bg-stage-sky', 'champagne-300': 'bg-stage-champagne-300', 'champagne-500': 'bg-stage-champagne-500',
  amber: 'bg-stage-amber', sage: 'bg-stage-sage', coral: 'bg-stage-coral', ash: 'bg-stage-ash', bone: 'bg-stage-bone',
}
</script>

<template>
  <span v-if="stage" class="inline-flex items-center gap-2 text-sm whitespace-nowrap">
    <span :class="['size-2 rounded-full', dot[stage.color] ?? 'bg-muted']" aria-hidden="true"></span>
    {{ stage.name }}
  </span>
</template>
