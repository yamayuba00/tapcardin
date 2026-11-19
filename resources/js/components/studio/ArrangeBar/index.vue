<script setup>
// Bilah atur untuk seleksi: ratakan (6 arah; 1 elemen = terhadap kanvas), distribusi (≥ 3), grup, duplikat, pola, hapus.
// Urutan tumpukan, kunci, dan salin ke sisi lain / pasangan ada di menu ⋮ agar bilah muat satu baris.
import { computed } from 'vue'
import {
  AlignCenterHorizontal, AlignCenterVertical, AlignEndHorizontal, AlignEndVertical, AlignHorizontalDistributeCenter,
  AlignStartHorizontal, AlignStartVertical, AlignVerticalDistributeCenter, ArrowDownToLine, ArrowUpToLine, ChevronDown, ChevronUp,
  Copy, CopyPlus, Grid2x2Plus, Group, Lock, LockOpen, Repeat, Trash2, Ungroup,
} from 'lucide-vue-next'
import AppMenu from '@/components/ui/AppMenu/index.vue'

const props = defineProps({
  count: { type: Number, required: true },
  grouped: Boolean,
  locked: Boolean,
  otherSide: { type: String, default: null }, // label sisi lain, mis. "belakang"
  pairLabel: { type: String, default: null }, // mis. "lanyard"
  target: { type: String, default: 'selection' }, // acuan ratakan: selection | canvas | safe
})
const emit = defineEmits(['align', 'distribute', 'group', 'ungroup', 'z', 'lock', 'other-side', 'to-pair', 'duplicate', 'remove', 'target', 'repeat'])
const TARGETS = [['selection', 'Seleksi'], ['canvas', 'Kanvas'], ['safe', 'Area aman']]
const minDistribute = () => (props.target === 'selection' ? 3 : 2)
const b = 'grid size-9 cursor-pointer place-items-center rounded-sm text-muted hover:bg-surface-2 hover:text-fg disabled:cursor-not-allowed disabled:opacity-40'
const sep = 'mx-1 h-6 w-px flex-none bg-line'
const more = computed(() => [
  { label: 'Paling depan (Ctrl+Shift+])', icon: ArrowUpToLine, onSelect: () => emit('z', 'front') },
  { label: 'Maju satu (Ctrl+])', icon: ChevronUp, onSelect: () => emit('z', 'forward') },
  { label: 'Mundur satu (Ctrl+[)', icon: ChevronDown, onSelect: () => emit('z', 'backward') },
  { label: 'Paling belakang (Ctrl+Shift+[)', icon: ArrowDownToLine, onSelect: () => emit('z', 'back') },
  { label: props.locked ? 'Buka kunci (Ctrl+L)' : 'Kunci posisi (Ctrl+L)', icon: props.locked ? Lock : LockOpen, onSelect: () => emit('lock') },
  props.otherSide && { label: `Salin ke sisi ${props.otherSide}`, icon: Copy, onSelect: () => emit('other-side') },
  props.pairLabel && { label: `Salin ke ${props.pairLabel} pasangan`, icon: Repeat, onSelect: () => emit('to-pair') },
].filter(Boolean))
const ALIGN = [
  ['left', AlignStartVertical, 'Rata kiri'], ['hcenter', AlignCenterVertical, 'Tengah horizontal'], ['right', AlignEndVertical, 'Rata kanan'],
  ['top', AlignStartHorizontal, 'Rata atas'], ['vmiddle', AlignCenterHorizontal, 'Tengah vertikal'], ['bottom', AlignEndHorizontal, 'Rata bawah'],
]
</script>

<template>
  <div class="flex flex-wrap items-center gap-0.5 rounded-md border border-line bg-surface px-1.5 py-1" role="toolbar" aria-label="Atur elemen terpilih">
    <span class="px-2 font-mono text-xs text-muted">{{ count }} dipilih</span>
    <span :class="sep"></span>
    <span class="flex items-center gap-0.5 rounded-sm bg-surface-2 p-0.5" role="group" aria-label="Ratakan terhadap">
      <button
        v-for="[v, label] in TARGETS" :key="v" type="button" :aria-pressed="target === v" :title="`Ratakan & sebar terhadap ${label.toLowerCase()}`"
        class="cursor-pointer rounded-sm px-2 py-1 text-xs text-muted aria-pressed:bg-accent aria-pressed:text-accent-ink" @click="emit('target', v)"
      >{{ label }}</button>
    </span>
    <button v-for="[how, icon, label] in ALIGN" :key="how" type="button" :class="b" :aria-label="label" :title="`${label}${count === 1 && target === 'selection' ? ' (terhadap kanvas)' : ''}`" @click="emit('align', how)"><component :is="icon" class="size-4" /></button>
    <span :class="sep"></span>
    <button type="button" :class="b" :disabled="count < minDistribute()" aria-label="Distribusi horizontal" :title="`Jarak horizontal sama rata (≥ ${minDistribute()})`" @click="emit('distribute', 'h')"><AlignHorizontalDistributeCenter class="size-4" /></button>
    <button type="button" :class="b" :disabled="count < minDistribute()" aria-label="Distribusi vertikal" :title="`Jarak vertikal sama rata (≥ ${minDistribute()})`" @click="emit('distribute', 'v')"><AlignVerticalDistributeCenter class="size-4" /></button>
    <span :class="sep"></span>
    <button v-if="!props.grouped" type="button" :class="b" :disabled="count < 2" aria-label="Kelompokkan" title="Kelompokkan (Ctrl+G)" @click="emit('group')"><Group class="size-4" /></button>
    <button v-else type="button" :class="b" aria-label="Pisahkan grup" title="Pisahkan grup (Ctrl+Shift+G)" @click="emit('ungroup')"><Ungroup class="size-4" /></button>
    <span :class="sep"></span>
    <button type="button" :class="b" aria-label="Duplikat" title="Duplikat (Ctrl+D)" @click="emit('duplicate')"><CopyPlus class="size-4" /></button>
    <button type="button" :class="b" aria-label="Ulangi berpola" title="Ulangi berpola (baris × kolom)" @click="emit('repeat')"><Grid2x2Plus class="size-4" /></button>
    <button type="button" :class="[b, 'hover:!text-danger']" aria-label="Hapus" title="Hapus (Delete)" @click="emit('remove')"><Trash2 class="size-4" /></button>
    <AppMenu :items="more" label="Urutan, kunci & salin" />
  </div>
</template>
