<script setup>
// Blok kode dokumentasi: label dan tombol salin di satu bar, isi dibungkus tanpa scroll horizontal.
import { Copy } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  label: { type: String, required: true },
  code: { type: String, required: true },
})
const toast = useToastStore()

async function copy() {
  try {
    await navigator.clipboard.writeText(props.code)
    toast.ok('Disalin.')
  } catch {
    toast.error('Tidak bisa menyalin.')
  }
}
</script>

<template>
  <figure class="grid min-w-0 overflow-hidden rounded-md border border-line">
    <figcaption class="flex items-center justify-between gap-2 border-b border-line bg-surface-2 py-1.5 pr-1.5 pl-3">
      <span class="label">{{ label }}</span>
      <AppButton variant="ghost" icon :aria-label="`Salin ${label}`" @click="copy">
        <Copy class="size-4" />
      </AppButton>
    </figcaption>
    <pre class="max-h-[420px] min-w-0 overflow-auto p-4 font-mono text-xs leading-relaxed break-words whitespace-pre-wrap">{{ code }}</pre>
  </figure>
</template>
