<script setup>
// DBD §11 Toast — --surface-1 + garis --line-strong; bawah (ponsel), kanan atas (desktop).
import { CircleAlert, CircleCheck, Info } from 'lucide-vue-next'
import { useToastStore } from '@/stores/toast'

const toasts = useToastStore()
const icon = { ok: CircleCheck, danger: CircleAlert, info: Info }
const color = { ok: 'text-ok', danger: 'text-danger', info: 'text-info' }
</script>

<template>
  <div class="fixed right-4 bottom-4 z-[60] grid w-[min(380px,calc(100vw-32px))] gap-2 lg:top-4 lg:bottom-auto" aria-live="polite">
    <button
      v-for="t in toasts.items"
      :key="t.id"
      type="button"
      :role="t.kind === 'danger' ? 'alert' : 'status'"
      class="cursor-pointer flex animate-rise items-start gap-3 rounded-md border border-line-strong bg-surface px-4 py-3 text-left text-sm"
      @click="toasts.dismiss(t.id)"
    >
      <component :is="icon[t.kind]" :class="['size-5 flex-none', color[t.kind]]" aria-hidden="true" />
      <span>{{ t.message }}</span>
    </button>
  </div>
</template>
