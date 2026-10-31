<script setup>
// DBD §11 Status chip — titik + teks warna status di --surface-2, mono 12 kapital (tidak hanya warna).
defineProps({
  tone: { type: String, default: 'muted' }, // ok | warn | danger | info | muted | outline
  dot: { type: Boolean, default: true },
})

const tones = {
  ok: 'bg-surface-2 text-ok',
  warn: 'bg-surface-2 text-warn',
  danger: 'bg-surface-2 text-danger',
  info: 'bg-surface-2 text-info',
  muted: 'bg-surface-2 text-muted',
  outline: 'border border-line-strong text-fg',
}
</script>

<template>
  <span :class="['inline-flex h-[26px] items-center gap-1.5 rounded-full px-2.5 font-mono text-xs font-medium tracking-[.08em] whitespace-nowrap uppercase', tones[tone]]">
    <span v-if="dot && tone !== 'outline'" class="size-[7px] rounded-full bg-current" aria-hidden="true"></span>
    <slot />
  </span>
</template>
