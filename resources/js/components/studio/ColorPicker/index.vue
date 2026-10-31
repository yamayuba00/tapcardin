<script setup>
// DBD §8.4 — pemilih warna desain cetak: warna brand kit (dirujuk `kit:*`, ikut berubah bila kit diubah),
// palet DBD, warna terakhir dipakai, dan warna bebas (color picker + hex). Tidak ada gradien.
import { computed, ref, watch } from 'vue'
import { Check, Slash } from 'lucide-vue-next'

const model = defineModel({ type: String, required: true })
const props = defineProps({
  palette: { type: Array, required: true },
  kit: { type: Object, default: null },
  label: String,
  allowNone: Boolean,
  allowKit: { type: Boolean, default: true },
})
const KIT = [['primary', 'Utama'], ['secondary', 'Sekunder'], ['background', 'Latar'], ['text', 'Teks']]
const RECENT_KEY = 'tapcard.studio.recentColors'
const recent = ref(read())
function read() {
  try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]').slice(0, 8) } catch { return [] }
}
function remember(hex) {
  recent.value = [hex, ...recent.value.filter((c) => c !== hex)].slice(0, 8)
  try { localStorage.setItem(RECENT_KEY, JSON.stringify(recent.value)) } catch { /* penyimpanan diblokir: abaikan */ }
}
const resolved = computed(() => (model.value?.startsWith('kit:') ? props.kit?.colors?.[model.value.slice(4)] : model.value))
const custom = ref(resolved.value ?? '')
watch(resolved, (v) => (custom.value = v ?? ''))
const isHex = (v) => /^#[0-9A-Fa-f]{6}$/.test(v)
const BLACK = `#${'0'.repeat(6)}` // nilai awal <input type=color> (wajib hex)

function setHex(v) {
  const hex = String(v).trim().toUpperCase()
  if (!isHex(hex)) return (custom.value = resolved.value ?? '')
  model.value = hex
  remember(hex)
}
const same = (a, b) => String(a ?? '').toUpperCase() === String(b ?? '').toUpperCase()
const swatch = 'grid size-7 cursor-pointer place-items-center rounded-sm border border-line-strong aria-checked:outline-2 aria-checked:outline-offset-1 aria-checked:outline-accent'
</script>

<template>
  <div class="grid gap-2.5">
    <span v-if="label" class="text-sm font-medium text-muted">{{ label }}</span>

    <div v-if="allowKit && kit" class="grid gap-1.5">
      <span class="label !text-[10px]">Brand kit · {{ kit.name }}</span>
      <div class="flex flex-wrap gap-1.5" role="radiogroup" :aria-label="`Warna brand kit ${kit.name}`">
        <button
          v-for="[k, name] in KIT" :key="k" type="button" role="radio" :aria-checked="model === `kit:${k}`" :aria-label="`Brand kit: ${name}`" :title="`${name} (ikut brand kit)`"
          :class="swatch" :style="{ backgroundColor: kit.colors[k] }" @click="model = `kit:${k}`"
        ><Check v-if="model === `kit:${k}`" class="size-4 text-bone-50 mix-blend-difference" /></button>
      </div>
    </div>

    <div class="flex flex-wrap gap-1.5" role="radiogroup" :aria-label="label || 'Palet'">
      <button v-if="allowNone" type="button" role="radio" :aria-checked="model === 'none'" aria-label="Tanpa warna" title="Tanpa isi" :class="swatch" @click="model = 'none'">
        <Slash class="size-4 text-danger" />
      </button>
      <button
        v-for="c in palette" :key="c.name" type="button" role="radio" :aria-checked="same(model, c.hex)" :aria-label="c.name" :title="c.name"
        :class="swatch" :style="{ backgroundColor: c.hex }" @click="setHex(c.hex)"
      ><Check v-if="same(model, c.hex)" class="size-4 text-bone-50 mix-blend-difference" /></button>
    </div>

    <div v-if="recent.length" class="flex flex-wrap items-center gap-1.5">
      <span class="text-xs text-muted">Terakhir:</span>
      <button v-for="c in recent" :key="c" type="button" :aria-label="`Warna ${c}`" :title="c" :class="['!size-6', swatch]" :style="{ backgroundColor: c }" @click="setHex(c)"></button>
    </div>

    <div class="flex items-center gap-2">
      <label class="relative size-9 flex-none cursor-pointer overflow-hidden rounded-sm border border-line-strong" :style="{ backgroundColor: resolved || 'transparent' }" title="Pilih warna bebas">
        <input type="color" :value="isHex(resolved) ? resolved : BLACK" class="absolute inset-0 cursor-pointer opacity-0" aria-label="Pilih warna bebas" @change="(e) => setHex(e.target.value)" />
      </label>
      <input
        v-model="custom" maxlength="7" spellcheck="false" aria-label="Kode warna hex"
        class="min-h-9 w-28 rounded-md border border-line-strong bg-surface px-3 font-mono text-sm uppercase"
        @change="setHex(custom)" @keydown.enter.prevent="setHex(custom)"
      />
      <span class="text-xs text-muted">{{ model?.startsWith('kit:') ? 'mengikuti brand kit' : 'warna bebas' }}</span>
    </div>
  </div>
</template>
