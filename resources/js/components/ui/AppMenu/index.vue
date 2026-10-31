<script setup>
// Menu tarik-turun ringkas (mis. "Lainnya" di bilah editor): tombol pemicu + daftar aksi.
// Tutup saat klik di luar / Esc / memilih item. items: [{ label, icon?, danger?, disabled?, onSelect }]
import { onBeforeUnmount, onMounted, ref, useId } from 'vue'
import { EllipsisVertical } from 'lucide-vue-next'

defineProps({ items: { type: Array, required: true }, label: { type: String, default: 'Lainnya' } })
const open = ref(false)
const root = ref(null)
const id = useId()

function onDoc(e) {
  if (open.value && root.value && !root.value.contains(e.target)) open.value = false
}
function onKey(e) {
  if (e.key === 'Escape') open.value = false
}
onMounted(() => { document.addEventListener('pointerdown', onDoc); document.addEventListener('keydown', onKey) })
onBeforeUnmount(() => { document.removeEventListener('pointerdown', onDoc); document.removeEventListener('keydown', onKey) })
function pick(item) {
  open.value = false
  item.onSelect?.()
}
</script>

<template>
  <div ref="root" class="relative">
    <button
      type="button" class="grid size-11 cursor-pointer place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-fg"
      :aria-label="label" :title="label" aria-haspopup="menu" :aria-expanded="open" :aria-controls="id" @click="open = !open"
    >
      <EllipsisVertical class="size-5" />
    </button>
    <div v-if="open" :id="id" role="menu" class="absolute right-0 z-40 mt-1 grid min-w-56 gap-0.5 rounded-md border border-line-strong bg-surface p-1.5">
      <button
        v-for="item in items" :key="item.label" type="button" role="menuitem" :disabled="item.disabled"
        class="flex min-h-10 cursor-pointer items-center gap-3 rounded-sm px-3 text-left text-sm hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-50"
        :class="item.danger ? 'text-danger' : 'text-fg'" @click="pick(item)"
      >
        <component :is="item.icon" v-if="item.icon" class="size-4 flex-none" /> {{ item.label }}
      </button>
    </div>
  </div>
</template>
