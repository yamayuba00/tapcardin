<script setup>
// DBD §11 Toast — --surface-1 + garis --line-strong; atas tengah (tidak menutupi menu bawah di ponsel).
import { CircleAlert, CircleCheck, Info } from 'lucide-vue-next'
import { useToastStore } from '@/stores/toast'

const toasts = useToastStore()
const icon = { ok: CircleCheck, danger: CircleAlert, info: Info }
const color = { ok: 'text-ok', danger: 'text-danger', info: 'text-info' }
</script>

<template>
  <div class="pointer-events-none fixed top-[calc(16px+env(safe-area-inset-top))] inset-x-0 z-[60] mx-auto grid w-[min(420px,calc(100vw-32px))] gap-2" aria-live="polite">
    <button
      v-for="t in toasts.items"
      :key="t.id"
      type="button"
      :role="t.kind === 'danger' ? 'alert' : 'status'"
      class="pointer-events-auto cursor-pointer flex animate-rise items-start gap-3 rounded-md border border-line-strong bg-surface px-4 py-3 text-left text-sm"
      @click="toasts.dismiss(t.id)"
    >
      <component :is="icon[t.kind]" :class="['size-5 flex-none', color[t.kind]]" aria-hidden="true" />
      <span>{{ t.message }}</span>
    </button>
  </div>
</template>
