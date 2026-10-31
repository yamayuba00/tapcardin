<script setup>
// DBD §11 Sheet/Modal — --surface-1 di atas --scrim, garis --line-strong, fokus terkunci, Esc & × menutup.
// Struktur: header (judul, deskripsi, ×) · isi yang bisa di-scroll · footer menempel (#footer, tombol rata kanan).
// Ponsel: tampil sebagai bottom sheet; `sheet` memaksa bentuk sheet di semua ukuran.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue'
import { X } from 'lucide-vue-next'

const props = defineProps({
  title: String,
  description: String,
  sheet: Boolean,
  wide: Boolean, // = size lg (kompatibel)
  size: { type: String, default: 'md' }, // sm | md | lg
  dismissible: { type: Boolean, default: true },
})
const emit = defineEmits(['close'])
const box = ref(null)
const id = useId()
const width = computed(() => ({ sm: 'sm:w-[min(420px,calc(100vw-32px))]', md: 'sm:w-[min(560px,calc(100vw-32px))]', lg: 'sm:w-[min(1000px,calc(100vw-32px))]' })[props.wide ? 'lg' : props.size])

function close() {
  if (props.dismissible) emit('close')
}
function onKey(e) {
  if (e.key === 'Escape') close()
  if (e.key !== 'Tab' || !box.value) return
  const f = box.value.querySelectorAll('button:not(:disabled), [href], input:not(:disabled), select, textarea, [tabindex]:not([tabindex="-1"])')
  if (!f.length) return
  const first = f[0]
  const last = f[f.length - 1]
  if (e.shiftKey && document.activeElement === first) { last.focus(); e.preventDefault() }
  else if (!e.shiftKey && document.activeElement === last) { first.focus(); e.preventDefault() }
}

let previous = null
onMounted(async () => {
  previous = document.activeElement
  document.addEventListener('keydown', onKey)
  document.documentElement.classList.add('overflow-hidden') // latar tidak ikut scroll
  await nextTick()
  ;(box.value?.querySelector('[autofocus], [data-body] input, [data-body] select, [data-body] textarea') ?? box.value?.querySelector('button'))?.focus()
})
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey)
  document.documentElement.classList.remove('overflow-hidden')
  previous?.focus?.()
})
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-40 bg-scrim" aria-hidden="true" @click="close"></div>
    <section
      ref="box"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="title ? `${id}-t` : undefined"
      :aria-describedby="description ? `${id}-d` : undefined"
      :class="[
        'fixed z-50 flex animate-rise flex-col border-line-strong bg-surface',
        'inset-x-0 bottom-0 max-h-[92dvh] rounded-t-lg border-t',
        sheet
          ? 'sm:bottom-6 sm:left-1/2 sm:w-[480px] sm:-translate-x-1/2 sm:rounded-lg sm:border'
          : ['sm:inset-x-auto sm:top-1/2 sm:bottom-auto sm:left-1/2 sm:max-h-[calc(100dvh-48px)] sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-lg sm:border', width],
      ]"
    >
      <div class="mx-auto mt-2.5 h-1 w-10 flex-none rounded-full bg-line-strong sm:hidden" aria-hidden="true"></div>
      <header v-if="title || dismissible" class="flex flex-none items-start gap-4 px-5 pt-4 pb-3 sm:px-6 sm:pt-5">
        <div class="grid min-w-0 flex-1 gap-1">
          <h2 v-if="title" :id="`${id}-t`" class="text-xl leading-tight">{{ title }}</h2>
          <p v-if="description" :id="`${id}-d`" class="text-sm text-muted">{{ description }}</p>
        </div>
        <button v-if="dismissible" type="button" class="-mt-1 -mr-2 grid size-10 flex-none cursor-pointer place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-fg" aria-label="Tutup" @click="close">
          <X class="size-5" />
        </button>
      </header>
      <div data-body class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 pb-5 sm:px-6">
        <div class="grid gap-4"><slot /></div>
      </div>
      <footer v-if="$slots.footer" class="flex flex-none flex-wrap items-center justify-end gap-2 border-t border-line px-5 py-4 pb-[calc(16px+env(safe-area-inset-bottom))] sm:px-6 sm:pb-4">
        <slot name="footer" />
      </footer>
    </section>
  </Teleport>
</template>
