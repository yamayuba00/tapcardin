<script setup>
// Navigasi ponsel bersama (app. & panel.): bilah bawah = tab utama + "Menu". "Menu" membuka lembar berisi semua
// kelompok menu sidebar dalam ubin 3 kolom, lalu aksi akun (slot `actions`). Lembar berada di bawah bilah
// (z-20 < z-30) sehingga tombol "Menu" tetap terlihat untuk menutupnya. Tertutup saat item diketuk (termasuk halaman
// yang sedang dibuka), pindah halaman, Esc, atau ketuk luar. Selama terbuka, konten di belakang tidak ikut ter-scroll.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { LayoutGrid, X } from 'lucide-vue-next'

const props = defineProps({
  tabs: { type: Array, required: true }, // [{ key, label, icon, to, badge? }] — maks. 3, tab ke-4 selalu "Menu"
  groups: { type: Array, required: true }, // [{ label, items: [{ key, label, icon, to, badge? }] }]
  active: { type: String, default: null }, // route.meta.nav
  title: { type: String, default: '' }, // nama akun di kepala lembar
  subtitle: { type: String, default: '' },
  initial: { type: String, default: '?' },
})

const route = useRoute()
const open = ref(false)
watch(() => route.fullPath, () => (open.value = false))
// kunci scroll konten di belakang lembar (yang scroll adalah <main>, bukan body)
watch(open, (v) => document.documentElement.toggleAttribute('data-sheet-open', v))
const inSheet = computed(() => !props.tabs.some((t) => t.key === props.active))
const onEsc = (e) => { if (e.key === 'Escape') open.value = false }
onMounted(() => document.addEventListener('keydown', onEsc))
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onEsc)
  document.documentElement.removeAttribute('data-sheet-open')
})

const tab = 'relative grid min-w-0 justify-items-center gap-1 px-1 pt-2.5 pb-2 text-[11px] font-medium text-muted no-underline aria-[current=page]:text-accent-text'
const badge = 'h-5 min-w-5 rounded-full bg-accent px-1.5 text-center font-mono text-[11px] leading-5 font-semibold text-accent-ink'
</script>

<template>
  <nav class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 border-t border-line bg-surface pb-[env(safe-area-inset-bottom)] print:hidden lg:hidden" aria-label="Navigasi">
    <RouterLink v-for="t in tabs" :key="t.key" :to="t.to" :aria-current="active === t.key ? 'page' : undefined" :class="tab">
      <component :is="t.icon" class="size-[22px]" aria-hidden="true" /><span class="max-w-full truncate">{{ t.label }}</span>
      <span v-if="t.badge" :class="['absolute top-1 left-1/2 ml-2', badge]">{{ t.badge }}</span>
    </RouterLink>
    <button type="button" :aria-expanded="open" aria-controls="mobile-menu" :aria-current="inSheet ? 'page' : undefined" :class="['cursor-pointer', tab]" @click="open = !open">
      <LayoutGrid class="size-[22px]" aria-hidden="true" /><span class="max-w-full truncate">Menu</span>
    </button>
  </nav>

  <template v-if="open">
    <div class="fixed inset-0 z-20 bg-scrim lg:hidden" aria-hidden="true" @click="open = false"></div>
    <section
      id="mobile-menu" role="dialog" aria-modal="true" aria-label="Menu"
      class="fixed inset-x-0 bottom-0 z-20 grid max-h-[85dvh] animate-rise content-start gap-4 overflow-y-auto overscroll-contain rounded-t-lg border-t border-line-strong bg-surface px-4 pt-3 pb-[calc(76px+env(safe-area-inset-bottom))] lg:hidden"
    >
      <div class="flex items-center justify-between">
        <span class="flex min-w-0 items-center gap-3">
          <span class="grid size-9 flex-none place-items-center rounded-sm bg-surface-2 font-display text-sm font-semibold" aria-hidden="true">{{ initial }}</span>
          <span class="grid min-w-0">
            <span class="truncate text-sm font-semibold">{{ title }}</span>
            <span class="label truncate !text-[10px] !tracking-[.12em]">{{ subtitle }}</span>
          </span>
        </span>
        <button type="button" class="grid size-10 flex-none cursor-pointer place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-fg" aria-label="Tutup menu" @click="open = false"><X class="size-5" /></button>
      </div>
      <nav v-for="g in groups" :key="g.label" class="grid gap-2" :aria-label="g.label">
        <p class="label !text-[10px]">{{ g.label }}</p>
        <div class="grid grid-cols-3 gap-2">
          <RouterLink
            v-for="item in g.items" :key="item.key" :to="item.to" :aria-current="active === item.key ? 'page' : undefined" @click="open = false"
            class="relative grid min-h-[72px] content-center justify-items-center gap-1.5 rounded-md border border-line px-1 py-2 text-center text-xs font-medium text-fg no-underline hover:bg-surface-2 aria-[current=page]:border-accent aria-[current=page]:text-accent-text"
          >
            <component :is="item.icon" class="size-[22px]" aria-hidden="true" />
            <span class="line-clamp-2 leading-tight">{{ item.label }}</span>
            <span v-if="item.badge" :class="['absolute top-1.5 right-1.5', badge]">{{ item.badge }}</span>
          </RouterLink>
        </div>
      </nav>
      <div class="grid gap-0.5 border-t border-line pt-3"><slot name="actions" /></div>
    </section>
  </template>
</template>
