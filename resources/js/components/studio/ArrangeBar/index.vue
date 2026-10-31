<script setup>
// Bilah atur untuk seleksi: ratakan (6 arah; 1 elemen = terhadap kanvas), distribusi (≥ 3), grup, urutan tumpukan,
// kunci, salin ke sisi lain / ke pasangan (kartu ↔ lanyard), duplikat, hapus.
import {
  AlignCenterHorizontal, AlignCenterVertical, AlignEndHorizontal, AlignEndVertical, AlignHorizontalDistributeCenter,
  AlignStartHorizontal, AlignStartVertical, AlignVerticalDistributeCenter, ArrowDownToLine, ArrowUpToLine, ChevronDown, ChevronUp,
  Copy, CopyPlus, Group, Lock, LockOpen, Repeat, Trash2, Ungroup,
} from 'lucide-vue-next'

const props = defineProps({
  count: { type: Number, required: true },
  grouped: Boolean,
  locked: Boolean,
  otherSide: { type: String, default: null }, // label sisi lain, mis. "belakang"
  pairLabel: { type: String, default: null }, // mis. "lanyard"
})
const emit = defineEmits(['align', 'distribute', 'group', 'ungroup', 'z', 'lock', 'other-side', 'to-pair', 'duplicate', 'remove'])
const b = 'grid size-9 cursor-pointer place-items-center rounded-sm text-muted hover:bg-surface-2 hover:text-fg disabled:cursor-not-allowed disabled:opacity-40'
const sep = 'mx-1 h-6 w-px flex-none bg-line'
const ALIGN = [
  ['left', AlignStartVertical, 'Rata kiri'], ['hcenter', AlignCenterVertical, 'Tengah horizontal'], ['right', AlignEndVertical, 'Rata kanan'],
  ['top', AlignStartHorizontal, 'Rata atas'], ['vmiddle', AlignCenterHorizontal, 'Tengah vertikal'], ['bottom', AlignEndHorizontal, 'Rata bawah'],
]
</script>

<template>
  <div class="flex flex-wrap items-center gap-0.5 rounded-md border border-line bg-surface px-1.5 py-1" role="toolbar" aria-label="Atur elemen terpilih">
    <span class="px-2 font-mono text-xs text-muted">{{ count }} dipilih</span>
    <span :class="sep"></span>
    <button v-for="[how, icon, label] in ALIGN" :key="how" type="button" :class="b" :aria-label="label" :title="`${label}${count === 1 ? ' (terhadap kanvas)' : ''}`" @click="emit('align', how)"><component :is="icon" class="size-4" /></button>
    <span :class="sep"></span>
    <button type="button" :class="b" :disabled="count < 3" aria-label="Distribusi horizontal" title="Jarak horizontal sama rata (≥ 3)" @click="emit('distribute', 'h')"><AlignHorizontalDistributeCenter class="size-4" /></button>
    <button type="button" :class="b" :disabled="count < 3" aria-label="Distribusi vertikal" title="Jarak vertikal sama rata (≥ 3)" @click="emit('distribute', 'v')"><AlignVerticalDistributeCenter class="size-4" /></button>
    <span :class="sep"></span>
    <button v-if="!props.grouped" type="button" :class="b" :disabled="count < 2" aria-label="Kelompokkan" title="Kelompokkan (Ctrl+G)" @click="emit('group')"><Group class="size-4" /></button>
    <button v-else type="button" :class="b" aria-label="Pisahkan grup" title="Pisahkan grup (Ctrl+Shift+G)" @click="emit('ungroup')"><Ungroup class="size-4" /></button>
    <button type="button" :class="b" aria-label="Paling depan" title="Paling depan (Ctrl+Shift+])" @click="emit('z', 'front')"><ArrowUpToLine class="size-4" /></button>
    <button type="button" :class="b" aria-label="Maju satu" title="Maju satu (Ctrl+])" @click="emit('z', 'forward')"><ChevronUp class="size-4" /></button>
    <button type="button" :class="b" aria-label="Mundur satu" title="Mundur satu (Ctrl+[)" @click="emit('z', 'backward')"><ChevronDown class="size-4" /></button>
    <button type="button" :class="b" aria-label="Paling belakang" title="Paling belakang (Ctrl+Shift+[)" @click="emit('z', 'back')"><ArrowDownToLine class="size-4" /></button>
    <span :class="sep"></span>
    <button type="button" :class="b" :aria-label="locked ? 'Buka kunci' : 'Kunci'" :title="locked ? 'Buka kunci (Ctrl+L)' : 'Kunci posisi (Ctrl+L)'" @click="emit('lock')"><component :is="locked ? Lock : LockOpen" class="size-4" /></button>
    <button type="button" :class="b" aria-label="Duplikat" title="Duplikat (Ctrl+D)" @click="emit('duplicate')"><CopyPlus class="size-4" /></button>
    <button v-if="otherSide" type="button" :class="b" :aria-label="`Salin ke sisi ${otherSide}`" :title="`Salin ke sisi ${otherSide}`" @click="emit('other-side')"><Copy class="size-4" /></button>
    <button v-if="pairLabel" type="button" :class="b" :aria-label="`Salin ke ${pairLabel}`" :title="`Salin ke ${pairLabel} pasangan (diskalakan otomatis)`" @click="emit('to-pair')"><Repeat class="size-4" /></button>
    <button type="button" :class="[b, 'hover:!text-danger']" aria-label="Hapus" title="Hapus (Delete)" @click="emit('remove')"><Trash2 class="size-4" /></button>
  </div>
</template>
