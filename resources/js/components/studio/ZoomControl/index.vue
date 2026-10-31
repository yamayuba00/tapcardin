<script setup>
// Zoom kanvas: 'fit' = selebar area kanvas; angka = kelipatan lebar dasar. Pintasan + / − / 0 dipasang halaman.
import { ZoomIn, ZoomOut } from 'lucide-vue-next'

const zoom = defineModel({ type: [String, Number], default: 'fit' })
const props = defineProps({ levels: { type: Array, default: () => [0.5, 0.75, 1, 1.5, 2, 3, 4] } })
const label = () => (zoom.value === 'fit' ? 'Pas' : `${Math.round(zoom.value * 100)}%`)
function by(dir) {
  const cur = zoom.value === 'fit' ? 1 : zoom.value
  const next = dir > 0 ? props.levels.find((z) => z > cur) : [...props.levels].reverse().find((z) => z < cur)
  if (next) zoom.value = next
}
defineExpose({ by })
const b = 'grid size-9 cursor-pointer place-items-center rounded-sm text-muted hover:bg-surface-2 hover:text-fg disabled:cursor-not-allowed disabled:opacity-40'
</script>

<template>
  <div class="flex items-center gap-0.5 rounded-md border border-line bg-surface p-0.5" role="group" aria-label="Zoom">
    <button type="button" :class="b" aria-label="Perkecil" title="Perkecil (−)" :disabled="zoom !== 'fit' && zoom <= levels[0]" @click="by(-1)"><ZoomOut class="size-4" /></button>
    <button type="button" class="min-w-12 cursor-pointer px-1 font-mono text-xs text-muted hover:text-fg" title="Pas di layar (0)" @click="zoom = 'fit'">{{ label() }}</button>
    <button type="button" :class="b" aria-label="Perbesar" title="Perbesar (+)" :disabled="zoom === levels[levels.length - 1]" @click="by(1)"><ZoomIn class="size-4" /></button>
  </div>
</template>
