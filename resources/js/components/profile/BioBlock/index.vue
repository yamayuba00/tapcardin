<script setup>
// Satu blok bio link versi publik (lihat App\Support\Bio\BioBlocks::present). Blok "contacts" dirender oleh
// PublicProfileView karena memakai daftar kontak profil. Video hanya dari penyedia yang diizinkan server (CSP frame-src).
import { computed, ref } from 'vue'
import { ArrowUpRight, X } from 'lucide-vue-next'

const props = defineProps({
  block: { type: Object, required: true },
  theme: { type: Object, required: true },
  corner: { type: String, default: 'rounded-md' },
  preview: Boolean,
})
const open = ref(null) // gambar galeri yang dibuka layar penuh

const external = computed(() => /^https?:/i.test(props.block.url ?? ''))
const buttonCls = computed(() => [
  'flex min-h-14 w-full items-center justify-center gap-2 px-6 text-center text-base font-semibold no-underline transition-opacity',
  props.corner,
  props.block.style === 'secondary'
    ? 'border border-line-strong bg-surface text-fg hover:bg-surface-2'
    : props.theme.button === 'solid' ? 'bg-accent text-accent-ink hover:opacity-90' : 'border-2 border-accent text-accent-text hover:bg-surface-2',
  props.preview ? 'pointer-events-none' : '',
])
const frameStyle = computed(() => (props.block.ratio ? { aspectRatio: props.block.ratio } : { height: `${props.block.height}px` }))
const PROVIDER = { youtube: 'YouTube', vimeo: 'Vimeo', tiktok: 'TikTok', spotify: 'Spotify' }
</script>

<template>
  <a
    v-if="block.type === 'button'"
    :href="preview ? undefined : block.url"
    :target="external ? '_blank' : undefined"
    :rel="external ? 'noopener nofollow' : undefined"
    :class="buttonCls"
  >
    <span class="[overflow-wrap:anywhere]">{{ block.label }}</span>
    <ArrowUpRight v-if="external" class="size-5 flex-none" aria-hidden="true" />
  </a>

  <section v-else-if="block.type === 'text'" :class="['grid gap-2', block.align === 'center' ? 'text-center' : '']">
    <h2 v-if="block.title" class="text-xl leading-tight font-bold [overflow-wrap:anywhere]">{{ block.title }}</h2>
    <p v-if="block.body" class="whitespace-pre-line [overflow-wrap:anywhere]">{{ block.body }}</p>
  </section>

  <div v-else-if="block.type === 'video'" :class="['overflow-hidden border border-line bg-surface-2', corner === 'rounded-full' ? 'rounded-lg' : corner]">
    <iframe
      :src="block.embed_url"
      :title="`Video ${PROVIDER[block.provider] ?? ''}`"
      class="block w-full"
      :style="frameStyle"
      loading="lazy"
      allow="autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture"
      allowfullscreen
      referrerpolicy="strict-origin-when-cross-origin"
      sandbox="allow-scripts allow-same-origin allow-presentation allow-popups"
    ></iframe>
  </div>

  <div v-else-if="block.type === 'gallery'">
    <ul :class="block.layout === 'carousel' ? 'flex snap-x snap-mandatory gap-3 overflow-x-auto pb-2 scrollbar-none' : 'grid grid-cols-2 gap-3'">
      <li
        v-for="(img, i) in block.images"
        :key="i"
        :class="[block.layout === 'carousel' ? 'w-[78%] flex-none snap-center' : '', block.layout !== 'carousel' && block.images.length % 2 && i === block.images.length - 1 ? 'col-span-2' : '']"
      >
        <figure class="grid gap-1.5">
          <button type="button" class="block cursor-zoom-in overflow-hidden" :class="corner === 'rounded-full' ? 'rounded-lg' : corner" :aria-label="img.caption || `Gambar ${i + 1}`" @click="open = img">
            <img :src="img.url" :alt="img.caption || ''" loading="lazy" class="aspect-square w-full bg-surface-2 object-cover" />
          </button>
          <figcaption v-if="img.caption" class="text-sm text-muted">{{ img.caption }}</figcaption>
        </figure>
      </li>
    </ul>
    <div v-if="open" class="fixed inset-0 z-50 grid place-items-center bg-scrim p-4" role="dialog" aria-modal="true" :aria-label="open.caption || 'Gambar'" @click="open = null">
      <button type="button" class="absolute top-4 right-4 grid size-11 cursor-pointer place-items-center rounded-full bg-surface text-fg" aria-label="Tutup"><X class="size-5" /></button>
      <figure class="grid max-h-full max-w-lg gap-2 text-center">
        <img :src="open.url" :alt="open.caption || ''" class="max-h-[80vh] w-auto rounded-md object-contain" />
        <figcaption v-if="open.caption" class="text-sm text-bone-50">{{ open.caption }}</figcaption>
      </figure>
    </div>
  </div>

  <hr v-else-if="block.type === 'divider' && block.style === 'line'" class="border-line" />
  <div v-else-if="block.type === 'divider'" class="h-4" aria-hidden="true"></div>
</template>
