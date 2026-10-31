<script setup>
// DBD §11 Usage meter — trek --surface-2, isi --accent; ≥80% --warn; penuh --danger.
import { computed } from 'vue'

const props = defineProps({ label: String, value: { type: Number, default: 0 }, max: { type: Number, default: null }, plain: Boolean })

const pct = computed(() => (props.max ? Math.min(100, Math.round((props.value / Math.max(props.max, 1)) * 100)) : 0))
const fill = computed(() => (props.plain || !props.max ? 'bg-accent' : pct.value >= 100 ? 'bg-danger' : pct.value >= 80 ? 'bg-warn' : 'bg-accent'))
</script>

<template>
  <div class="grid gap-2">
    <div class="flex justify-between text-sm">
      <span>{{ label }}</span>
      <span class="font-mono"><slot>{{ value }} / {{ max ?? '∞' }}</slot></span>
    </div>
    <div class="h-2 overflow-hidden rounded-full bg-surface-2">
      <div :class="['h-full', fill]" :style="{ width: (plain ? value : pct) + '%' }"></div>
    </div>
  </div>
</template>
