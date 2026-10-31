<script setup>
// DBD §11 Select — pengganti <select> bawaan: single & multiple, pencarian (otomatis bila > 8 opsi),
// grup, deskripsi/ikon per opsi, chip untuk multiple, keyboard (↑↓ Home End Enter Esc, ketik untuk lompat),
// pola ARIA combobox + listbox. Panel di-teleport ke body; di ponsel tampil sebagai bottom sheet.
// API lama tetap: v-model, options [{ value, label, description?, icon?, group?, disabled? }], placeholder.
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'
import { Check, ChevronDown, Search, X } from 'lucide-vue-next'

defineOptions({ inheritAttrs: false })
const model = defineModel({ type: [String, Number, Array, null], default: '' })
const props = defineProps({
  options: { type: Array, default: () => [] },
  placeholder: String,
  multiple: Boolean,
  searchable: { type: Boolean, default: null }, // null = otomatis
  clearable: Boolean,
  max: { type: Number, default: null },
  disabled: Boolean,
  invalid: Boolean,
  emptyText: { type: String, default: 'Tidak ada pilihan yang cocok.' },
})

const id = useId()
const trigger = ref(null)
const panel = ref(null)
const searchEl = ref(null)
const open = ref(false)
const query = ref('')
const active = ref(-1)
const pos = ref({})
const mobile = ref(false)

const same = (a, b) => String(a) === String(b)
const selected = computed(() => (props.multiple ? (Array.isArray(model.value) ? model.value : []) : model.value))
const isSelected = (v) => (props.multiple ? selected.value.some((s) => same(s, v)) : model.value !== '' && model.value != null && same(model.value, v))
const chosen = computed(() => props.options.filter((o) => isSelected(o.value)))
const canSearch = computed(() => props.searchable ?? props.options.length > 8)
const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  return q ? props.options.filter((o) => `${o.label} ${o.description ?? ''}`.toLowerCase().includes(q)) : props.options
})
/** Baris yang dirender: header grup + opsi (indeks opsi dipakai untuk navigasi keyboard). */
const rows = computed(() => {
  const out = []
  let group
  filtered.value.forEach((o, i) => {
    if (o.group && o.group !== group) out.push({ header: (group = o.group) })
    out.push({ option: o, index: i })
  })
  return out
})
const showClear = computed(() => (props.multiple ? selected.value.length > 0 : props.clearable && chosen.value.length > 0))
const limitReached = computed(() => props.multiple && props.max !== null && selected.value.length >= props.max)

function place() {
  const r = trigger.value?.getBoundingClientRect()
  if (!r) return
  mobile.value = window.innerWidth < 640
  if (mobile.value) return (pos.value = {})
  const below = window.innerHeight - r.bottom
  const up = below < 280 && r.top > below
  const maxH = Math.min(340, (up ? r.top : below) - 16)
  pos.value = {
    left: `${Math.min(r.left, window.innerWidth - Math.max(r.width, 240) - 8)}px`,
    width: `${Math.max(r.width, 240)}px`,
    maxHeight: `${maxH}px`,
    ...(up ? { bottom: `${window.innerHeight - r.top + 6}px` } : { top: `${r.bottom + 6}px` }),
  }
}
async function show() {
  if (props.disabled || open.value) return
  open.value = true
  query.value = ''
  active.value = Math.max(0, filtered.value.findIndex((o) => isSelected(o.value)))
  place()
  await nextTick()
  canSearch.value ? searchEl.value?.focus() : panel.value?.focus()
  scrollActive()
}
function hide(focusTrigger = true) {
  open.value = false
  if (focusTrigger) trigger.value?.focus()
}
function pick(o) {
  if (!o || o.disabled) return
  if (props.multiple) {
    const has = isSelected(o.value)
    if (!has && limitReached.value) return
    model.value = has ? selected.value.filter((s) => !same(s, o.value)) : [...selected.value, o.value]
  } else {
    model.value = o.value
    hide()
  }
}
function clear() {
  model.value = props.multiple ? [] : ''
}
const removeChip = (v) => (model.value = selected.value.filter((s) => !same(s, v)))
function selectAll() {
  const vals = filtered.value.filter((o) => !o.disabled).map((o) => o.value)
  const merged = [...new Set([...selected.value, ...vals].map(String))].map((s) => vals.find((v) => same(v, s)) ?? selected.value.find((v) => same(v, s)))
  model.value = props.max ? merged.slice(0, props.max) : merged
}

let typed = ''
let typedAt = 0
function onKey(e) {
  if (!open.value) {
    if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) { e.preventDefault(); show() }
    return
  }
  const n = filtered.value.length
  const move = (i) => { e.preventDefault(); active.value = (i + n) % Math.max(n, 1); scrollActive() }
  if (e.key === 'ArrowDown') move(active.value + 1)
  else if (e.key === 'ArrowUp') move(active.value - 1)
  else if (e.key === 'Home') move(0)
  else if (e.key === 'End') move(n - 1)
  else if (e.key === 'Enter' || (e.key === ' ' && !canSearch.value)) { e.preventDefault(); pick(filtered.value[active.value]) }
  else if (e.key === 'Escape') { e.preventDefault(); hide() }
  else if (e.key === 'Tab') hide(false)
  else if (!canSearch.value && e.key.length === 1) {
    // ketik huruf untuk lompat ke opsi yang diawali huruf itu
    typed = Date.now() - typedAt > 600 ? e.key : typed + e.key
    typedAt = Date.now()
    const i = filtered.value.findIndex((o) => o.label.toLowerCase().startsWith(typed.toLowerCase()))
    if (i >= 0) move(i)
  }
}
function scrollActive() {
  nextTick(() => panel.value?.querySelector(`[data-index="${active.value}"]`)?.scrollIntoView({ block: 'nearest' }))
}
watch(query, () => (active.value = filtered.value.length ? 0 : -1))

const outside = (e) => { if (open.value && !panel.value?.contains(e.target) && !trigger.value?.contains(e.target)) hide(false) }
const reposition = () => open.value && place()
watch(open, (v) => {
  const fn = v ? 'addEventListener' : 'removeEventListener'
  document[fn]('pointerdown', outside, true)
  window[fn]('resize', reposition)
  window[fn]('scroll', reposition, true)
})
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', outside, true)
  window.removeEventListener('resize', reposition)
  window.removeEventListener('scroll', reposition, true)
})
const VISIBLE_CHIPS = 3
</script>

<template>
  <button
    ref="trigger"
    type="button"
    role="combobox"
    :aria-expanded="open"
    aria-haspopup="listbox"
    :aria-controls="`${id}-list`"
    :aria-invalid="invalid || undefined"
    :disabled="disabled"
    v-bind="$attrs"
    :class="[
      'relative flex min-h-12 w-full cursor-pointer items-center gap-2 rounded-md border bg-surface py-2 pr-10 pl-4 text-left text-base text-fg transition disabled:cursor-not-allowed disabled:opacity-60',
      invalid ? 'border-danger' : open ? 'border-accent' : 'border-line-strong hover:border-fg',
    ]"
    @click="open ? hide() : show()"
    @keydown="onKey"
  >
    <!-- multiple: chip -->
    <span v-if="multiple && chosen.length" class="flex min-w-0 flex-1 flex-wrap gap-1.5">
      <span v-for="o in chosen.slice(0, VISIBLE_CHIPS)" :key="o.value" class="inline-flex max-w-[160px] items-center gap-1 rounded-sm bg-surface-2 py-0.5 pr-1 pl-2 text-sm">
        <span class="truncate">{{ o.label }}</span>
        <span role="button" tabindex="-1" class="grid size-5 cursor-pointer place-items-center rounded-sm text-muted hover:bg-line hover:text-fg" :aria-label="`Hapus ${o.label}`" @click.stop="removeChip(o.value)"><X class="size-3.5" /></span>
      </span>
      <span v-if="chosen.length > VISIBLE_CHIPS" class="inline-flex items-center rounded-sm bg-surface-2 px-2 py-0.5 font-mono text-xs text-muted">+{{ chosen.length - VISIBLE_CHIPS }}</span>
    </span>
    <!-- single -->
    <span v-else-if="!multiple && chosen.length" class="flex min-w-0 flex-1 items-center gap-2">
      <component :is="chosen[0].icon" v-if="chosen[0].icon" class="size-4 flex-none text-accent-text" aria-hidden="true" />
      <span class="truncate">{{ chosen[0].label }}</span>
    </span>
    <span v-else class="min-w-0 flex-1 truncate text-muted">{{ placeholder || 'Pilih…' }}</span>

    <span
      v-if="showClear && !disabled" role="button" tabindex="-1"
      class="absolute top-1/2 right-8 grid size-6 -translate-y-1/2 cursor-pointer place-items-center rounded-sm text-muted hover:bg-surface-2 hover:text-fg"
      aria-label="Kosongkan pilihan" @click.stop="clear"
    ><X class="size-4" /></span>
    <ChevronDown :class="['pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-muted transition-transform', open ? 'rotate-180' : '']" aria-hidden="true" />
  </button>

  <Teleport to="body">
    <div v-if="open && mobile" class="fixed inset-0 z-[60] bg-scrim" aria-hidden="true" @click="hide()"></div>
    <div
      v-if="open"
      ref="panel"
      tabindex="-1"
      :class="[
        'fixed z-[61] flex flex-col overflow-hidden border border-line-strong bg-surface outline-none',
        mobile ? 'inset-x-0 bottom-0 max-h-[75dvh] rounded-t-lg pb-[env(safe-area-inset-bottom)]' : 'rounded-md',
      ]"
      :style="pos"
      @keydown="onKey"
    >
      <div v-if="canSearch" class="relative flex-none border-b border-line p-2">
        <Search class="pointer-events-none absolute top-1/2 left-4.5 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
        <input
          ref="searchEl" v-model="query" type="search" placeholder="Cari…" autocomplete="off"
          class="h-10 w-full rounded-sm border border-line bg-bg pr-3 pl-9 text-sm text-fg outline-none focus:border-accent"
          role="searchbox" :aria-controls="`${id}-list`"
          :aria-activedescendant="active >= 0 ? `${id}-o${active}` : undefined"
        />
      </div>

      <ul :id="`${id}-list`" role="listbox" :aria-multiselectable="multiple || undefined" :aria-label="$attrs['aria-label'] || placeholder" class="min-h-0 flex-1 overflow-y-auto overscroll-contain p-1.5">
        <li
          v-if="!multiple && placeholder && !canSearch"
          role="option" :aria-selected="!chosen.length"
          class="flex min-h-10 cursor-pointer items-center gap-3 rounded-sm px-3 text-sm text-muted hover:bg-surface-2"
          @click="clear(); hide()"
        >{{ placeholder }}</li>
        <template v-for="(r, ri) in rows" :key="ri">
          <li v-if="r.header" role="presentation" class="label px-3 pt-3 pb-1 !text-[10px]">{{ r.header }}</li>
          <li
            v-else
            :id="`${id}-o${r.index}`"
            :data-index="r.index"
            role="option"
            :aria-selected="isSelected(r.option.value)"
            :aria-disabled="r.option.disabled || (multiple && !isSelected(r.option.value) && limitReached) || undefined"
            :class="[
              'flex min-h-10 cursor-pointer items-center gap-3 rounded-sm px-3 py-2 text-sm aria-disabled:cursor-not-allowed aria-disabled:opacity-50',
              active === r.index ? 'bg-surface-2' : '',
            ]"
            @mousemove="active = r.index"
            @click="pick(r.option)"
          >
            <span v-if="multiple" :class="['grid size-[18px] flex-none place-items-center rounded-sm border', isSelected(r.option.value) ? 'border-accent bg-accent text-accent-ink' : 'border-line-strong']" aria-hidden="true">
              <Check v-if="isSelected(r.option.value)" class="size-3.5" />
            </span>
            <component :is="r.option.icon" v-if="r.option.icon" class="size-4 flex-none text-accent-text" aria-hidden="true" />
            <span class="grid min-w-0 flex-1">
              <span class="truncate text-fg">{{ r.option.label }}</span>
              <span v-if="r.option.description" class="truncate text-xs text-muted">{{ r.option.description }}</span>
            </span>
            <Check v-if="!multiple && isSelected(r.option.value)" class="size-4 flex-none text-accent-text" aria-hidden="true" />
          </li>
        </template>
        <li v-if="!filtered.length" class="px-3 py-6 text-center text-sm text-muted">{{ emptyText }}</li>
      </ul>

      <div v-if="multiple" class="flex flex-none items-center justify-between gap-2 border-t border-line px-3 py-2 text-sm">
        <span class="text-muted">{{ selected.length }} dipilih{{ max ? ` dari maks ${max}` : '' }}</span>
        <span class="flex gap-1">
          <button type="button" class="cursor-pointer rounded-sm px-2 py-1 text-accent-text hover:bg-surface-2" @click="selectAll">Pilih semua</button>
          <button type="button" class="cursor-pointer rounded-sm px-2 py-1 text-muted hover:bg-surface-2" :disabled="!selected.length" @click="clear">Kosongkan</button>
          <button v-if="mobile" type="button" class="cursor-pointer rounded-sm bg-accent px-3 py-1 font-semibold text-accent-ink" @click="hide()">Selesai</button>
        </span>
      </div>
    </div>
  </Teleport>
</template>
