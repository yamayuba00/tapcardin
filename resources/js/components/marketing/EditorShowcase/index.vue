<script setup>
// Etalase editor di landing page — memakai komponen editor yang sama dengan Studio (CardSide, LayerList,
// LanyardStrip, LanyardMockup). Editor kartu: ganti template, depan/belakang, pilih & geser elemen (perangkat
// dengan pointer presisi). Editor lanyard: lebar tali, sisi cetak, pengait, perkiraan harga, pratinjau gabungan.
// Semua perubahan hanya lokal di halaman ini.
import { computed, onMounted, ref, watch } from 'vue'
import {
  BadgeCheck, Circle, CreditCard, Hexagon, ImagePlus, Layers, Minus, MousePointer2, Radio, RotateCcw, Ruler, Square, Star, Type,
} from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import CardSide from '@/components/studio/CardSide/index.vue'
import LanyardMockup from '@/components/studio/LanyardMockup/index.vue'
import LanyardStrip from '@/components/studio/LanyardStrip/index.vue'
import LayerList from '@/components/studio/LayerList/index.vue'
import { rupiah } from '@/lib/format'

const props = defineProps({
  templates: { type: Array, required: true },
  vars: { type: Object, default: () => ({}) },
  qr: { type: Object, default: null },
  editor: { type: Object, required: true }, // { paired_card, lanyards: {width: {front, back}}, widths, hardware }
})

const mode = ref('card')
const MODES = [{ value: 'card', label: 'Editor kartu' }, { value: 'lanyard', label: 'Editor lanyard' }]

// ---- editor kartu ----
const clone = (o) => JSON.parse(JSON.stringify(o))
const tplKey = ref(props.templates[0]?.key)
const tpl = computed(() => props.templates.find((t) => t.key === tplKey.value) ?? props.templates[0])
const design = ref(clone(tpl.value.preview.landscape))
const side = ref('front')
const selection = ref(['name'])
watch(tplKey, () => reset())
function reset() {
  design.value = clone(tpl.value.preview.landscape)
  selection.value = side.value === 'front' ? ['name'] : []
}
watch(side, () => (selection.value = []))
function change(changes) {
  const s = design.value[side.value]
  for (const c of changes) {
    const i = s.layers.findIndex((l) => l.id === c.id)
    if (i >= 0) s.layers[i] = c.layer
  }
}
function toggle(id, key) {
  const l = design.value[side.value].layers.find((x) => x.id === id)
  if (l) l[key] = !l[key]
}
// geser elemen hanya untuk mouse/trackpad; di layar sentuh kanvas tetap bisa di-scroll seperti biasa
const fine = ref(false)
onMounted(() => (fine.value = window.matchMedia('(pointer: fine)').matches))
const TOOLS = [Type, Square, Circle, Hexagon, Star, Minus, Radio, ImagePlus]

// ---- editor lanyard ----
// tahan terhadap data lama / ter-cache yang berbentuk objek alih-alih array
const asList = (v) => (Array.isArray(v) ? v : Object.values(v ?? {}))
const widths = computed(() => asList(props.editor.widths))
const hardwareList = computed(() => asList(props.editor.hardware))
const widthMm = ref(widths.value.find((w) => w.width_mm === 20)?.width_mm ?? widths.value[0]?.width_mm)
const sides = ref(2)
const hardware = ref(hardwareList.value[0]?.key)
const tile = computed(() => props.editor.lanyards[widthMm.value])
const width = computed(() => widths.value.find((w) => w.width_mm === widthMm.value))
const hw = computed(() => hardwareList.value.find((h) => h.key === hardware.value))
const unit = computed(() => (sides.value === 2 ? width.value.price_double : width.value.price_single) + (hw.value?.price ?? 0))

const tab = 'flex min-h-11 w-full cursor-pointer items-center justify-between gap-3 rounded-md border px-3 py-2 text-left text-sm'
const tabOn = (on) => (on ? 'border-accent bg-surface-2 text-fg' : 'border-line text-muted hover:border-line-strong hover:text-fg')
</script>

<template>
  <div class="grid gap-5">
    <AppSegmented v-model="mode" label="Pilih editor" :options="MODES" />

    <div class="overflow-hidden rounded-lg border border-line-strong bg-surface">
      <!-- bilah atas ala editor -->
      <div class="flex flex-wrap items-center gap-3 border-b border-line px-4 py-2.5">
        <component :is="mode === 'card' ? CreditCard : Ruler" class="size-5 text-accent-text" aria-hidden="true" />
        <span class="min-w-0 truncate text-sm font-semibold">{{ mode === 'card' ? `Kartu Rina — ${tpl.name}` : `Lanyard — ${vars.company}` }}</span>
        <AppBadge tone="ok" class="hidden sm:inline-flex"><BadgeCheck class="size-3.5" /> Siap cetak</AppBadge>
        <div class="ml-auto flex items-center gap-2">
          <AppSegmented v-if="mode === 'card'" v-model="side" label="Sisi kartu" :options="[{ value: 'front', label: 'Depan' }, { value: 'back', label: 'Belakang' }]" />
          <AppSegmented v-else :model-value="sides" label="Sisi cetak" :options="[{ value: 1, label: '1 sisi' }, { value: 2, label: '2 sisi' }]" @update:model-value="(v) => (sides = Number(v))" />
          <AppButton v-if="mode === 'card'" variant="ghost" icon aria-label="Atur ulang desain" title="Atur ulang" @click="reset"><RotateCcw class="size-4" /></AppButton>
        </div>
      </div>

      <!-- editor kartu -->
      <div v-if="mode === 'card'" class="grid lg:grid-cols-[56px_minmax(0,1fr)_300px]">
        <div class="hidden flex-col items-center gap-1 border-r border-line py-3 lg:flex" aria-hidden="true">
          <span class="grid size-10 place-items-center rounded-md bg-surface-2 text-fg"><MousePointer2 class="size-5" /></span>
          <span v-for="(t, i) in TOOLS" :key="i" class="grid size-10 place-items-center rounded-md text-muted"><component :is="t" class="size-5" /></span>
        </div>

        <div class="grid min-w-0 place-items-center gap-3 bg-surface-2 px-4 py-8 sm:px-10">
          <div class="w-full max-w-[520px]">
            <CardSide
              :side="design[side]" :vars="vars" :qr="qr" :editable="fine" :selection="selection"
              @select="(ids) => (selection = ids)" @change="change"
            />
          </div>
          <p class="text-center text-sm text-muted">
            <template v-if="fine">Coba klik &amp; geser teks di kartu. Garis bantu, safe zone, dan elemen terkunci sama seperti di editor.</template>
            <template v-else>Buka di laptop untuk mencoba menggeser elemen — di sini Anda bisa ganti template &amp; sisi kartu.</template>
          </p>
        </div>

        <div class="grid content-start gap-5 border-t border-line p-4 lg:border-t-0 lg:border-l">
          <div class="grid gap-2">
            <span class="label">Template</span>
            <div class="grid grid-cols-4 gap-2 lg:grid-cols-2">
              <button
                v-for="t in templates" :key="t.key" type="button" :aria-pressed="tplKey === t.key" :aria-label="`Template ${t.name}`" :title="t.name"
                class="cursor-pointer overflow-hidden rounded-md border-2 p-0.5" :class="tplKey === t.key ? 'border-accent' : 'border-transparent hover:border-line-strong'"
                @click="tplKey = t.key"
              ><CardSide :side="t.preview.landscape.front" :vars="vars" :qr="qr" trim /></button>
            </div>
          </div>
          <div class="grid gap-2">
            <span class="label flex items-center gap-2"><Layers class="size-4" /> Lapisan</span>
            <div class="max-h-60 overflow-y-auto">
              <LayerList
                :layers="design[side].layers" :selection="selection" :vars="vars"
                @select="(ids) => (selection = ids)" @toggle-hide="(id) => toggle(id, 'hidden')" @toggle-lock="(id) => toggle(id, 'locked')"
              />
            </div>
          </div>
        </div>
      </div>

      <!-- editor lanyard -->
      <div v-else class="grid lg:grid-cols-[minmax(0,1fr)_300px]">
        <div class="grid min-w-0 content-start gap-6 bg-surface-2 px-4 py-8 sm:px-8">
          <div class="overflow-x-auto"><LanyardMockup :lanyard="tile.front" :back="sides === 2 ? tile.back : null" :card="editor.paired_card.front" :vars="vars" :qr="qr" :hardware="hardware" :scale="1.7" /></div>
          <div class="grid gap-3">
            <div v-for="s in (sides === 2 ? ['front', 'back'] : ['front'])" :key="s" class="grid gap-1.5">
              <span class="label">{{ s === 'front' ? 'Sisi depan' : 'Sisi belakang' }} · pola {{ tile[s].size.w }} mm diulang sepanjang 900 mm</span>
              <div class="overflow-hidden rounded-sm"><LanyardStrip :side="tile[s]" :vars="vars" :length="420" :scale="1.6" /></div>
            </div>
          </div>
          <p class="text-sm text-muted">Kartu berlubang lanyard otomatis dipasangkan, dan warna brand kit dipakai bersama oleh kartu &amp; tali.</p>
        </div>

        <div class="grid content-start gap-5 border-t border-line p-4 lg:border-t-0 lg:border-l">
          <fieldset class="grid gap-2">
            <legend class="label mb-2">Lebar tali</legend>
            <button v-for="w in widths" :key="w.width_mm" type="button" :aria-pressed="widthMm === w.width_mm" :class="[tab, tabOn(widthMm === w.width_mm)]" @click="widthMm = w.width_mm">
              <span class="font-medium">{{ w.label }}</span>
              <span class="font-mono text-xs">{{ rupiah(sides === 2 ? w.price_double : w.price_single) }}</span>
            </button>
          </fieldset>
          <fieldset class="grid gap-2">
            <legend class="label mb-2">Pengait</legend>
            <button v-for="h in hardwareList" :key="h.key" type="button" :aria-pressed="hardware === h.key" :class="[tab, tabOn(hardware === h.key)]" @click="hardware = h.key">
              <span class="font-medium">{{ h.name }}</span>
              <span class="font-mono text-xs">{{ h.price ? `+${rupiah(h.price)}` : 'Termasuk' }}</span>
            </button>
          </fieldset>
          <div class="grid gap-1 rounded-md bg-surface-2 p-4">
            <span class="label">Perkiraan harga</span>
            <span class="font-display text-2xl">{{ rupiah(unit) }} <span class="text-base font-normal text-muted">/ pcs</span></span>
            <span class="text-sm text-muted">Minimal {{ width.min_qty }} pcs · cetak sublimasi {{ sides }} sisi</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
