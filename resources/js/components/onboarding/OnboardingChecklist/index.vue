<script setup>
// Panduan pengguna baru (Beranda): langkah-langkah dihitung server dari data nyata, jadi tercentang sendiri.
// Kelengkapan bio ditampilkan sebagai meter + butir yang belum diisi. Bisa ditutup (tersimpan di akun).
import { computed, ref } from 'vue'
import { Check, ChevronDown, X } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import { meApi } from '@/api/workspace'

const props = defineProps({ data: { type: Object, required: true } })
const emit = defineEmits(['dismissed'])
const next = computed(() => props.data.steps.find((s) => !s.done)?.key)
const open = ref(next.value)
const missing = computed(() => props.data.profile.items.filter((i) => !i.done))

async function dismiss() {
  await meApi.onboarding(true).catch(() => null)
  emit('dismissed')
}
</script>

<template>
  <section class="grid gap-4 rounded-md border border-line-strong bg-surface p-5" aria-labelledby="onb-title">
    <div class="flex flex-wrap items-start gap-3">
      <div class="grid min-w-0 flex-1 gap-1">
        <span class="label !text-accent-text">Mulai di sini · {{ data.done }}/{{ data.total }} selesai</span>
        <h2 id="onb-title" class="font-display text-xl font-bold">Siapkan TapCard Anda</h2>
      </div>
      <AppButton variant="ghost" icon aria-label="Sembunyikan panduan" title="Sembunyikan panduan" @click="dismiss"><X class="size-5" /></AppButton>
    </div>
    <div class="h-1.5 w-full overflow-hidden rounded-full bg-surface-2" role="progressbar" :aria-valuenow="data.done" aria-valuemin="0" :aria-valuemax="data.total" aria-label="Kemajuan panduan">
      <div class="h-full rounded-full bg-accent transition-[width]" :style="{ width: `${(data.done / data.total) * 100}%` }"></div>
    </div>

    <ol class="grid">
      <li v-for="(s, i) in data.steps" :key="s.key" class="border-t border-line first:border-t-0">
        <button type="button" class="flex w-full cursor-pointer items-center gap-3 py-3 text-left" :aria-expanded="open === s.key" @click="open = open === s.key ? null : s.key">
          <span class="grid size-7 flex-none place-items-center rounded-full text-sm" :class="s.done ? 'bg-solid-ok text-on-solid' : 'border border-line-strong text-muted'">
            <Check v-if="s.done" class="size-4" aria-label="selesai" /><span v-else class="num">{{ i + 1 }}</span>
          </span>
          <span class="flex-1 font-medium" :class="s.done ? 'text-muted line-through' : 'text-fg'">{{ s.title }}</span>
          <span v-if="s.key === 'profile'" class="num text-sm text-muted">{{ data.profile.percent }}%</span>
          <ChevronDown class="size-4 flex-none text-muted transition-transform" :class="open === s.key ? 'rotate-180' : ''" aria-hidden="true" />
        </button>
        <div v-if="open === s.key" class="grid gap-3 pb-4 pl-10">
          <p class="text-sm text-muted">{{ s.text }}</p>
          <template v-if="s.key === 'profile'">
            <div class="h-1.5 w-full max-w-sm overflow-hidden rounded-full bg-surface-2" aria-hidden="true">
              <div class="h-full rounded-full bg-accent" :style="{ width: `${data.profile.percent}%` }"></div>
            </div>
            <ul v-if="missing.length" class="grid gap-1 text-sm">
              <li v-for="m in missing" :key="m.key" class="flex items-center gap-2 text-muted"><span class="size-1.5 rounded-full bg-line-strong" aria-hidden="true"></span>{{ m.label }}</li>
            </ul>
          </template>
          <div v-if="!s.done" class="flex flex-wrap gap-2">
            <AppButton v-if="s.cta" :to="s.cta.to">{{ s.cta.label }}</AppButton>
            <AppButton v-if="s.secondary" variant="secondary" :to="s.secondary.to">{{ s.secondary.label }}</AppButton>
          </div>
        </div>
      </li>
    </ol>
  </section>
</template>
