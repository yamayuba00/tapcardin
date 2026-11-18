<script setup>
// Pemilih font koleksi: cari + saring kategori, tiap nama tampil dalam font-nya sendiri (dimuat saat daftar dibuka).
import { computed, ref, watch } from 'vue'
import { ChevronDown, Search } from 'lucide-vue-next'
import { FONT_CATEGORIES, FONT_LIST, loadFont } from '@/lib/fonts'

const model = defineModel({ type: String, required: true })
const props = defineProps({ allowed: { type: Object, default: null } }) // {family: weights} dari katalog server
const open = ref(false)
const query = ref('')
const cat = ref('')
const fonts = computed(() => FONT_LIST.filter((f) => !props.allowed || props.allowed[f.family]))
const shown = computed(() => {
  const q = query.value.trim().toLowerCase()
  return fonts.value.filter((f) => (!cat.value || f.category === cat.value) && (!q || f.family.toLowerCase().includes(q)))
})
watch(open, (v) => { if (v) fonts.value.forEach((f) => loadFont(f.family, f.weights.includes(400) ? 400 : f.weights[0])) })
watch(model, (v) => loadFont(v, 400), { immediate: true })
function pick(f) {
  model.value = f.family
  open.value = false
}
const chip = 'cursor-pointer rounded-full border px-2.5 py-1 text-xs'
</script>

<template>
  <div class="grid gap-2">
    <button
      type="button" :aria-expanded="open"
      class="flex min-h-12 w-full cursor-pointer items-center justify-between gap-2 rounded-md border border-line-strong bg-surface px-4 text-left text-base hover:border-fg"
      @click="open = !open"
    >
      <span class="truncate" :style="{ fontFamily: `'${model}'` }">{{ model }}</span>
      <ChevronDown :class="['size-4 flex-none text-muted transition-transform', open ? 'rotate-180' : '']" aria-hidden="true" />
    </button>
    <div v-if="open" class="grid gap-2 rounded-md border border-line bg-surface p-2">
      <label class="relative block">
        <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
        <input v-model="query" type="search" placeholder="Cari font…" aria-label="Cari font" class="h-10 w-full rounded-sm border border-line bg-bg pr-3 pl-9 text-sm text-fg outline-none focus:border-accent" />
      </label>
      <div class="flex flex-wrap gap-1.5" role="group" aria-label="Kategori font">
        <button type="button" :class="[chip, !cat ? 'border-accent text-accent-text' : 'border-line text-muted']" @click="cat = ''">Semua</button>
        <button v-for="(label, key) in FONT_CATEGORIES" :key="key" type="button" :class="[chip, cat === key ? 'border-accent text-accent-text' : 'border-line text-muted']" @click="cat = key">{{ label }}</button>
      </div>
      <ul class="max-h-64 overflow-y-auto overscroll-contain" role="listbox" aria-label="Font">
        <li v-for="f in shown" :key="f.family">
          <button
            type="button" role="option" :aria-selected="f.family === model"
            :class="['flex w-full cursor-pointer items-baseline justify-between gap-3 rounded-sm px-3 py-2 text-left hover:bg-surface-2', f.family === model ? 'bg-surface-2' : '']"
            @click="pick(f)"
          >
            <span class="truncate text-lg" :style="{ fontFamily: `'${f.family}'` }">{{ f.family }}</span>
            <span class="flex-none text-xs text-muted">{{ FONT_CATEGORIES[f.category] }}</span>
          </button>
        </li>
        <li v-if="!shown.length" class="px-3 py-4 text-center text-sm text-muted">Tidak ada font yang cocok.</li>
      </ul>
    </div>
  </div>
</template>
