<script setup>
// Bilah alat sisipkan: teks, bentuk (kotak, elips, poligon, bintang, garis, lingkaran, cincin), ikon (pustaka lucide),
// gambar/logo unggahan, dan logo brand kit. Setiap elemen baru diletakkan di tengah kanvas.
import { computed, ref } from 'vue'
import { BadgeCheck, Circle, Hexagon, ImagePlus, Minus, Radio, Search, Shapes, Square, Star, Type } from 'lucide-vue-next'
import AppModal from '@/components/ui/AppModal/index.vue'
import { GLYPHS } from '@/lib/glyphs'

const props = defineProps({ hasKitLogo: Boolean, rings: { type: Boolean, default: true } })
const emit = defineEmits(['add', 'image', 'glyph', 'kit-logo'])
const fileInput = ref(null)
const picking = ref(false)
const q = ref('')
const glyphs = computed(() => Object.entries(GLYPHS).filter(([n]) => n.includes(q.value.trim().toLowerCase())))
const TOOLS = computed(() => [
  { type: 'text', label: 'Teks (T)', icon: Type },
  { type: 'rect', label: 'Kotak (R)', icon: Square },
  { type: 'ellipse', label: 'Elips (O)', icon: Circle },
  { type: 'polygon', label: 'Poligon', icon: Hexagon },
  { type: 'star', label: 'Bintang', icon: Star },
  { type: 'line', label: 'Garis (L)', icon: Minus },
  ...(props.rings ? [{ type: 'rings', label: 'Cincin sinyal', icon: Radio }] : []),
])
const btn = 'grid size-11 cursor-pointer place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-fg'
function pick(name) {
  emit('glyph', name)
  picking.value = false
}
function onFile(e) {
  const f = e.target.files?.[0]
  e.target.value = ''
  if (f) emit('image', f)
}
</script>

<template>
  <nav class="flex gap-1 overflow-x-auto rounded-md border border-line bg-surface p-1.5 lg:flex-col lg:overflow-visible" aria-label="Sisipkan elemen">
    <button v-for="t in TOOLS" :key="t.type" type="button" :class="btn" :aria-label="`Tambah ${t.label}`" :title="t.label" @click="emit('add', t.type)">
      <component :is="t.icon" class="size-5" />
    </button>
    <button type="button" :class="btn" aria-label="Tambah ikon" title="Ikon" @click="picking = true"><Shapes class="size-5" /></button>
    <span class="mx-1 w-px self-stretch bg-line lg:mx-0 lg:my-1 lg:h-px lg:w-auto" aria-hidden="true"></span>
    <button type="button" :class="btn" aria-label="Unggah gambar atau logo" title="Gambar / logo (PNG, JPG, WebP)" @click="fileInput.click()"><ImagePlus class="size-5" /></button>
    <button type="button" :class="[btn, hasKitLogo ? '' : 'opacity-50']" aria-label="Tambah logo brand kit" title="Logo brand kit" @click="emit('kit-logo')"><BadgeCheck class="size-5" /></button>
    <input ref="fileInput" type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="onFile" />
  </nav>

  <AppModal v-if="picking" title="Pilih ikon" description="Ikon garis yang tajam saat dicetak; warna & ukuran bisa diubah." size="lg" @close="picking = false">
    <div class="relative">
      <Search class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
      <input v-model="q" type="search" autofocus placeholder="Cari ikon, mis. phone, star, leaf" aria-label="Cari ikon" class="h-11 w-full rounded-md border border-line-strong bg-bg pr-3 pl-10 outline-none focus:border-accent" />
    </div>
    <div class="grid grid-cols-4 gap-2 sm:grid-cols-8">
      <button v-for="[name, icon] in glyphs" :key="name" type="button" class="grid cursor-pointer justify-items-center gap-1 rounded-md border border-line p-2 text-[11px] text-muted hover:border-accent hover:text-fg" :title="name" @click="pick(name)">
        <component :is="icon" class="size-6 text-fg" /><span class="w-full truncate text-center">{{ name }}</span>
      </button>
    </div>
  </AppModal>
</template>
