<script setup>
// Daftar lapisan sisi aktif (atas = paling depan): klik pilih (Shift/Ctrl = tambah), seret untuk mengubah urutan,
// mata = tampil/sembunyi (tidak dicetak), gembok = kunci posisi. Elemen sistem selalu di atas & terkunci.
import { computed, ref } from 'vue'
import { Eye, EyeOff, GripVertical, Group, Lock, LockOpen } from 'lucide-vue-next'
import { expandGroups, isSystem, layerLabel } from '@/lib/design'

const props = defineProps({ layers: { type: Array, required: true }, selection: { type: Array, default: () => [] }, vars: Object })
const emit = defineEmits(['select', 'reorder', 'toggle-hide', 'toggle-lock'])
const ordered = computed(() => [...props.layers].reverse())
const userCount = computed(() => props.layers.filter((l) => !isSystem(l)).length)
const dragId = ref(null)
const overId = ref(null)

function click(e, l) {
  const ids = expandGroups([l.id], props.layers)
  if (e.shiftKey || e.metaKey || e.ctrlKey) {
    const has = props.selection.includes(l.id)
    emit('select', has ? props.selection.filter((id) => !ids.includes(id)) : [...new Set([...props.selection, ...ids])])
  } else {
    emit('select', ids)
  }
}
function drop(target) {
  if (!dragId.value || dragId.value === target.id || isSystem(target)) return reset()
  // indeks tampilan terbalik → indeks di antara layer pengguna (0 = paling belakang)
  const user = props.layers.filter((l) => !isSystem(l))
  emit('reorder', dragId.value, user.findIndex((l) => l.id === target.id))
  reset()
}
const reset = () => { dragId.value = null; overId.value = null }
</script>

<template>
  <ul class="grid gap-0.5" aria-label="Lapisan">
    <li
      v-for="l in ordered" :key="l.id"
      :draggable="!isSystem(l)"
      :class="['flex items-center rounded-sm', overId === l.id && dragId !== l.id ? 'outline-2 outline-accent' : '']"
      @dragstart="dragId = l.id" @dragover.prevent="overId = l.id" @dragleave="overId === l.id && (overId = null)" @drop.prevent="drop(l)" @dragend="reset"
    >
      <GripVertical v-if="!isSystem(l)" class="size-3.5 flex-none cursor-grab text-muted" aria-hidden="true" />
      <span v-else class="w-3.5"></span>
      <button
        type="button"
        :aria-current="selection.includes(l.id) ? 'true' : undefined"
        :class="['flex min-h-9 min-w-0 flex-1 cursor-pointer items-center gap-2 rounded-sm px-2 text-left text-sm text-muted hover:bg-surface-2 hover:text-fg aria-[current=true]:bg-surface-2 aria-[current=true]:text-fg', l.hidden ? 'line-through opacity-60' : '']"
        @click="click($event, l)"
      >
        <Lock v-if="isSystem(l)" class="size-3.5 flex-none text-accent-text" aria-label="Elemen wajib" />
        <Group v-else-if="l.group" class="size-3.5 flex-none text-accent-text" aria-label="Dalam grup" />
        <span class="truncate">{{ layerLabel(l, vars) }}</span>
      </button>
      <template v-if="!isSystem(l)">
        <button type="button" class="grid size-8 cursor-pointer place-items-center rounded-sm text-muted hover:bg-surface-2 hover:text-fg" :aria-label="l.lock ? 'Buka kunci' : 'Kunci'" :title="l.lock ? 'Buka kunci' : 'Kunci posisi'" @click="emit('toggle-lock', [l.id])">
          <component :is="l.lock ? Lock : LockOpen" :class="['size-4', l.lock ? 'text-accent-text' : '']" />
        </button>
        <button type="button" class="grid size-8 cursor-pointer place-items-center rounded-sm text-muted hover:bg-surface-2 hover:text-fg" :aria-label="l.hidden ? 'Tampilkan' : 'Sembunyikan'" :title="l.hidden ? 'Tampilkan (ikut dicetak)' : 'Sembunyikan (tidak dicetak)'" @click="emit('toggle-hide', l.id)">
          <component :is="l.hidden ? EyeOff : Eye" class="size-4" />
        </button>
      </template>
    </li>
    <li v-if="!userCount" class="px-2 py-4 text-center text-sm text-muted">Belum ada elemen. Tambah dari bilah alat di kiri.</li>
  </ul>
</template>
