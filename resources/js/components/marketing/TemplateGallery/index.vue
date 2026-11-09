<script setup>
// Etalase template default (8 dulu, "Lihat semua" untuk koleksi lengkap; kartu dirender saat terlihat) — dirender dengan FlipCard/CardSide yang sama dengan Design Studio,
// jadi yang dilihat calon pembeli = yang dicetak. Arahkan kursor (atau ketuk) untuk melihat sisi belakang.
import { computed, reactive, ref } from 'vue'
import { RefreshCcw } from 'lucide-vue-next'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import FlipCard from '@/components/studio/FlipCard/index.vue'
import LazyRender from '@/components/common/LazyRender/index.vue'

const props = defineProps({
  templates: { type: Array, required: true },
  vars: { type: Object, required: true },
  qr: { type: Object, required: true },
})
const orientation = ref('landscape')
const flipped = reactive({})
const INITIAL = 8
const showAll = ref(false)
const visibleTemplates = computed(() => (showAll.value ? props.templates : props.templates.slice(0, INITIAL)))
</script>

<template>
  <div class="grid gap-8">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <AppSegmented v-model="orientation" label="Orientasi" :options="[{ value: 'landscape', label: 'Lanskap' }, { value: 'portrait', label: 'Potret (lanyard)' }]" />
      <span class="inline-flex items-center gap-2 text-sm text-muted"><RefreshCcw class="size-4" /> Arahkan kursor atau ketuk kartu untuk membalik</span>
    </div>

    <ul :class="['grid gap-6', orientation === 'portrait' ? 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4' : 'sm:grid-cols-2 lg:grid-cols-4']">
      <li v-for="t in visibleTemplates" :key="t.key" class="grid content-start gap-3">
        <button type="button" class="cursor-pointer block w-full text-left" :aria-label="`Balik kartu ${t.name}`" @click="flipped[t.key] = !flipped[t.key]">
          <LazyRender :aspect="`${t.preview[orientation].front.size.w} / ${t.preview[orientation].front.size.h}`">
            <FlipCard
              :front="t.preview[orientation].front"
              :back="t.preview[orientation].back"
              :vars="vars"
              :qr="qr"
              :flipped="!!flipped[t.key]"
              hover-flip
              sample
            />
          </LazyRender>
        </button>
        <div class="grid gap-0.5">
          <span class="font-display text-lg font-bold">{{ t.name }}</span>
          <span class="text-sm text-muted">{{ t.description }}</span>
        </div>
      </li>
    </ul>
    <div v-if="!showAll && templates.length > INITIAL" class="flex justify-center">
      <button type="button" class="inline-flex min-h-11 cursor-pointer items-center rounded-md border border-line-strong px-5 font-semibold hover:bg-surface-2" @click="showAll = true">
        Lihat semua {{ templates.length }} desain
      </button>
    </div>
  </div>
</template>
