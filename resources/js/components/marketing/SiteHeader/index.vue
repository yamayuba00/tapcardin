<script setup>
// Navigasi landing page: tautan jangkar ke bagian halaman + masuk/daftar ke app.{base}.
// Scroll-spy: tautan bagian yang sedang dibaca ditandai aktif (aria-current="location").
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { Menu, X } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import BrandLogo from '@/components/common/BrandLogo/index.vue'
import { config } from '@/config'

const LINKS = [
  { id: 'cara-kerja', label: 'Cara kerja' },
  { id: 'template', label: 'Template' },
  { id: 'editor', label: 'Editor' },
  { id: 'bio-link', label: 'Bio link' },
  { id: 'fitur', label: 'Fitur' },
  { id: 'harga', label: 'Harga' },
  { id: 'faq', label: 'FAQ' },
]
const open = ref(false)
const active = ref('')
const OFFSET = 120 // header 64 px + ruang baca

function spy() {
  const doc = document.documentElement
  if (window.scrollY + window.innerHeight >= doc.scrollHeight - 4) {
    active.value = LINKS[LINKS.length - 1].id // dasar halaman: bagian terakhir pendek, tetap tandai
    return
  }
  let current = ''
  for (const l of LINKS) {
    const el = document.getElementById(l.id)
    if (el && el.getBoundingClientRect().top <= OFFSET) current = l.id
  }
  active.value = current
}
let frame = 0
const onScroll = () => {
  cancelAnimationFrame(frame)
  frame = requestAnimationFrame(spy)
}
onMounted(() => {
  window.addEventListener('scroll', onScroll, { passive: true })
  window.addEventListener('resize', onScroll)
  spy()
})
onBeforeUnmount(() => {
  window.removeEventListener('scroll', onScroll)
  window.removeEventListener('resize', onScroll)
  cancelAnimationFrame(frame)
})
function go(id) {
  active.value = id
  open.value = false
}
</script>

<template>
  <header class="sticky top-0 z-30 border-b border-line bg-bg">
    <div class="mx-auto flex h-16 max-w-[1200px] items-center gap-6 px-4 sm:px-6">
      <a href="/" class="no-underline" aria-label="TapCard beranda"><BrandLogo /></a>
      <nav class="hidden h-full flex-1 items-stretch gap-6 text-sm lg:flex" aria-label="Utama">
        <a
          v-for="l in LINKS" :key="l.id" :href="`#${l.id}`" :aria-current="active === l.id ? 'location' : undefined"
          class="relative flex items-center text-muted no-underline transition-colors hover:text-fg aria-[current=location]:font-medium aria-[current=location]:text-fg after:absolute after:inset-x-0 after:-bottom-px after:h-0.5 after:rounded-full after:bg-transparent aria-[current=location]:after:bg-accent"
          @click="go(l.id)"
        >{{ l.label }}</a>
      </nav>
      <div class="ml-auto hidden items-center gap-2 sm:flex">
        <AppButton variant="ghost" :href="`${config.appUrl}/login`">Masuk</AppButton>
        <AppButton :href="`${config.appUrl}/login`">Mulai gratis</AppButton>
      </div>
      <AppButton variant="ghost" icon class="ml-auto sm:ml-0 lg:hidden" :aria-label="open ? 'Tutup menu' : 'Buka menu'" :aria-expanded="open" @click="open = !open">
        <component :is="open ? X : Menu" class="size-5" />
      </AppButton>
    </div>
    <nav v-if="open" class="grid gap-1 border-t border-line px-4 py-3 lg:hidden" aria-label="Menu">
      <a
        v-for="l in LINKS" :key="l.id" :href="`#${l.id}`" :aria-current="active === l.id ? 'location' : undefined"
        class="flex items-center gap-2 rounded-md px-3 py-2 text-fg no-underline hover:bg-surface-2 aria-[current=location]:bg-surface-2 aria-[current=location]:font-semibold"
        @click="go(l.id)"
      ><span class="size-1.5 rounded-full" :class="active === l.id ? 'bg-accent' : 'bg-transparent'" aria-hidden="true"></span>{{ l.label }}</a>
      <div class="mt-2 grid gap-2 sm:hidden">
        <AppButton block :href="`${config.appUrl}/login`">Mulai gratis</AppButton>
        <AppButton variant="secondary" block :href="`${config.appUrl}/login`">Masuk</AppButton>
      </div>
    </nav>
  </header>
</template>
