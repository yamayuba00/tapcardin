<script setup>
// Satu kontak di bio link. Elemen mengikuti aksi jenisnya: link/map/call = <a>, copy = tombol Salin
// (rekening, e-wallet, id), image = buka QRIS layar penuh, none = teks (mis. jam operasional).
// variant: list | buttons | grid (dari tema bio link).
import { computed, ref } from 'vue'
import { Check, ChevronRight, Copy, Maximize2 } from 'lucide-vue-next'
import { contactIcon } from '@/lib/contactIcons'

const props = defineProps({
  link: { type: Object, required: true }, // { label, display, icon, action, url, copy, image }
  variant: { type: String, default: 'list' },
  corner: { type: String, default: 'rounded-md' },
  preview: Boolean,
})
const emit = defineEmits(['image'])
const copied = ref(false)
const tag = computed(() => (props.link.url ? 'a' : ['copy', 'image'].includes(props.link.action) ? 'button' : 'div'))
const sub = computed(() => (props.link.display && props.link.display !== props.link.label ? props.link.display : null))
const Trail = computed(() => (props.link.action === 'copy' ? (copied.value ? Check : Copy) : props.link.action === 'image' ? Maximize2 : props.link.url ? ChevronRight : null))
const interactive = computed(() => tag.value !== 'div')

async function onClick(e) {
  if (props.link.action === 'copy') {
    e.preventDefault()
    try { await navigator.clipboard.writeText(props.link.copy) } catch { /* izin clipboard ditolak: teks tetap terlihat */ }
    copied.value = true
    setTimeout(() => (copied.value = false), 1600)
  } else if (props.link.action === 'image') {
    e.preventDefault()
    emit('image', props.link)
  } else if (props.preview) {
    e.preventDefault()
  }
}
const base = 'text-fg no-underline'
const cls = computed(() => ({
  list: ['flex min-h-14 w-full items-center gap-3 border-b border-line py-2 text-left', interactive.value ? 'cursor-pointer hover:bg-surface-2' : ''],
  buttons: ['relative flex min-h-14 w-full items-center justify-center border border-line-strong bg-surface px-12 py-2 text-center font-medium', props.corner, interactive.value ? 'cursor-pointer hover:bg-surface-2' : ''],
  grid: ['grid min-h-24 w-full content-center justify-items-center gap-1.5 border border-line-strong bg-surface p-3 text-center text-sm font-medium', props.corner, interactive.value ? 'cursor-pointer hover:bg-surface-2' : ''],
})[props.variant])
</script>

<template>
  <component
    :is="tag" :href="link.url || undefined" :type="tag === 'button' ? 'button' : undefined"
    :rel="tag === 'a' ? 'noopener nofollow' : undefined" :class="[base, cls]"
    :aria-label="link.action === 'copy' ? `Salin ${link.label}: ${link.copy}` : undefined" @click="onClick"
  >
    <component :is="contactIcon(link.icon)" :class="variant === 'buttons' ? 'absolute left-4 size-[18px] text-accent-text' : variant === 'grid' ? 'size-6 text-accent-text' : 'size-[18px] flex-none text-accent-text'" aria-hidden="true" />
    <span :class="variant === 'list' ? 'grid min-w-0 flex-1' : 'grid min-w-0'">
      <span :class="variant === 'grid' ? 'line-clamp-2 [overflow-wrap:anywhere]' : 'truncate'">{{ link.label }}</span>
      <span v-if="sub" :class="['truncate text-xs font-normal text-muted', variant === 'grid' ? 'line-clamp-1' : '']">{{ sub }}</span>
    </span>
    <span v-if="copied" class="text-xs text-ok" role="status">Disalin</span>
    <component :is="Trail" v-if="Trail && variant !== 'grid'" :class="['size-[18px] flex-none', copied ? 'text-ok' : 'text-muted', variant === 'buttons' ? 'absolute right-4' : '']" aria-hidden="true" />
  </component>
</template>
