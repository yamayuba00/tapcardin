<script setup>
// Funnel lead terbuka per tahap: bar relatif terhadap tahap terbanyak; tautan ke dashboard lead lengkap.
import { computed } from 'vue'
import { ArrowRight } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import { useAuthStore } from '@/stores/auth'
import { num } from '@/lib/format'

const props = defineProps({ stages: { type: Array, required: true } })
const contactLabel = computed(() => useAuthStore().contactLabel.toLowerCase())
const max = computed(() => Math.max(1, ...props.stages.map((s) => s.count)))
const total = computed(() => props.stages.reduce((a, s) => a + s.count, 0))
</script>

<template>
  <section class="grid gap-3" aria-label="Funnel lead">
    <div class="flex items-center justify-between gap-3">
      <span class="text-sm text-muted">{{ num(total) }} lead terbuka</span>
      <AppButton variant="ghost" to="/connections/dashboard">Ringkasan {{ contactLabel }} <ArrowRight class="size-4" /></AppButton>
    </div>
    <ol class="grid gap-2">
      <li v-for="s in stages" :key="s.id" class="grid grid-cols-[minmax(0,7rem)_1fr_auto] items-center gap-3 text-sm">
        <span class="truncate">{{ s.name }}</span>
        <span class="h-2 overflow-hidden rounded-full bg-surface-2" aria-hidden="true">
          <span class="block h-full rounded-full bg-accent" :style="{ width: `${(s.count / max) * 100}%` }"></span>
        </span>
        <span class="num font-medium">{{ num(s.count) }}</span>
      </li>
    </ol>
  </section>
</template>
