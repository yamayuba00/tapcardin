<script setup>
// Satu blok bio link versi publik (lihat App\Support\Bio\BioBlocks::present). Blok "contacts" dirender oleh
// PublicProfileView karena memakai daftar kontak profil. Video hanya dari penyedia yang diizinkan server (CSP frame-src).
import { computed, ref } from 'vue'
import { ArrowUpRight, ClipboardList } from 'lucide-vue-next'
import BioFormSheet from '@/components/profile/BioFormSheet/index.vue'

const props = defineProps({
  block: { type: Object, required: true },
  theme: { type: Object, required: true },
  corner: { type: String, default: 'rounded-md' },
  preview: Boolean,
  owner: { type: String, default: '' }, // nama pemilik (teks persetujuan formulir)
})
const formOpen = ref(false)

const external = computed(() => /^https?:/i.test(props.block.url ?? ''))
const buttonCls = computed(() => [
  'flex min-h-14 w-full items-center justify-center gap-2 px-6 text-center text-base font-semibold no-underline transition-opacity',
  props.corner,
  props.block.style === 'secondary'
    ? 'border border-line-strong bg-surface text-fg hover:bg-surface-2'
    : props.theme.button === 'solid' ? 'bg-accent text-accent-ink hover:opacity-90' : 'border-2 border-accent text-accent-text hover:bg-surface-2',
  props.preview && props.block.type === 'button' ? 'pointer-events-none' : '',
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

  <!-- formulir: tombol membuka lembar isian (di preview editor tetap bisa dicoba, tanpa mengirim) -->
  <template v-else-if="block.type === 'form'">
    <button type="button" :class="['cursor-pointer', buttonCls]" @click="formOpen = true">
      <ClipboardList class="size-5 flex-none" aria-hidden="true" /><span class="[overflow-wrap:anywhere]">{{ block.label }}</span>
    </button>
    <BioFormSheet v-if="formOpen" :block="block" :owner="owner" :preview="preview" @close="formOpen = false" />
  </template>

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

  <hr v-else-if="block.type === 'divider' && block.style === 'line'" class="border-line" />
  <div v-else-if="block.type === 'divider'" class="h-4" aria-hidden="true"></div>
</template>
