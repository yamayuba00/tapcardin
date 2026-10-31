<script setup>
// DBD §11 Tap zone — idle --line-strong; waiting pulsa --accent; ok --ok; fail --danger.
import { computed } from 'vue'
import { CircleCheck, CircleX, Nfc } from 'lucide-vue-next'

const props = defineProps({ state: { type: String, default: 'idle' } }) // idle | waiting | ok | fail

const color = computed(() => ({ idle: 'text-line-strong', waiting: 'text-accent', ok: 'text-ok', fail: 'text-danger' })[props.state])
const icon = computed(() => (props.state === 'ok' ? CircleCheck : props.state === 'fail' ? CircleX : Nfc))
</script>

<template>
  <div :class="['relative mx-auto grid size-[220px] place-items-center', color]">
    <span
      v-for="(inset, i) in ['inset-0', 'inset-7', 'inset-14']"
      :key="i"
      :class="['absolute rounded-full border border-current', inset, state === 'waiting' ? 'animate-tap-pulse' : '']"
      :style="state === 'waiting' ? { animationDelay: i * 200 + 'ms' } : undefined"
    ></span>
    <component :is="icon" :class="['size-12', state === 'idle' || state === 'waiting' ? 'text-accent-text' : '']" aria-hidden="true" />
  </div>
</template>
