<script setup>
// "Diperbarui 10.21 · otomatis tiap 1 menit" + tombol muat ulang. Dipakai bersama useAutoRefresh.
import { computed } from 'vue'
import { RefreshCw } from 'lucide-vue-next'

const props = defineProps({
  lastAt: { type: Date, required: true },
  refreshing: Boolean,
  every: { type: Number, default: 60_000 },
})
defineEmits(['refresh'])

const time = computed(() => props.lastAt.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }))
const interval = computed(() => (props.every >= 60_000 ? `${Math.round(props.every / 60_000)} menit` : `${Math.round(props.every / 1000)} detik`))
</script>

<template>
  <div class="flex items-center gap-2 text-sm text-muted">
    <button
      type="button" class="grid size-9 flex-none cursor-pointer place-items-center rounded-md border border-line-strong text-fg hover:bg-surface-2 disabled:cursor-wait disabled:opacity-60"
      :disabled="refreshing" aria-label="Muat ulang sekarang" title="Muat ulang sekarang" @click="$emit('refresh')"
    >
      <RefreshCw class="size-4" :class="refreshing ? 'animate-spin' : ''" aria-hidden="true" />
    </button>
    <span aria-live="polite">{{ refreshing ? 'Memuat ulang…' : `Diperbarui ${time}` }} · otomatis tiap {{ interval }}</span>
  </div>
</template>
