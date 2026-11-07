<script setup>
// Mode sederhana editor merchandise: pilih layout siap pakai untuk sisi aktif, isi teks & logo, pilih warna bahan
// dan tulisan. Semua perubahan lewat mesin editor yang sama (undo/redo, simpan, cek cetak); elemen bebas tetap
// bisa ditambah di tab Edit / Lapisan.
import { computed, ref } from 'vue'
import { Check, ImageUp, LayoutTemplate, Sparkles } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import MerchMockup from '@/components/studio/MerchMockup/index.vue'
import { studioApi } from '@/api/studio'
import { buildLayout, isSimple, layoutsFor, titleLayers } from '@/lib/merchLayouts'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  ed: { type: Object, required: true }, // useDesignEditor()
  spec: { type: Object, required: true }, // merch_spec
  designId: { type: String, required: true },
})
const toast = useToastStore()
const ed = props.ed
const side = computed(() => ed.side.value)
const which = computed(() => ed.active.value)
const kit = computed(() => ed.kit.value)
const palette = computed(() => ed.catalog.value?.palette ?? [])
const hex = (name) => palette.value.find((c) => c.name === name)?.hex

// warna bahan yang lazim untuk kaos/tas/mug & warna tulisan (nama token palet → hex dari katalog)
const FABRICS = [['bone-0', 'Putih'], ['bone-100', 'Krem'], ['ash-400', 'Abu'], ['onyx-800', 'Abu tua'], ['onyx-950', 'Hitam'], ['sky-700', 'Biru dongker'], ['sage-700', 'Hijau army'], ['coral-700', 'Merah marun'], ['umber-600', 'Cokelat'], ['champagne-300', 'Pasir']]
const INKS = [['ink-950', 'Hitam'], ['bone-0', 'Putih'], ['champagne-500', 'Emas'], ['sky-700', 'Biru'], ['coral-700', 'Merah'], ['sage-700', 'Hijau']]

const fabrics = computed(() => FABRICS.filter(([n]) => hex(n)))
const inks = computed(() => INKS.filter(([n]) => hex(n)))
const layouts = computed(() => layoutsFor(props.spec.silhouette, which.value))
const layer = (id) => side.value.layers.find((l) => l.id === id)
const hasSimple = computed(() => side.value.layers.some(isSimple))
const fabric = computed(() => side.value.background?.value)
/** Warna tulisan: dari sisi ini, atau sisi lain, atau otomatis kontras dengan warna bahan. */
const otherTitle = computed(() => ed.sides[which.value === 'front' ? 'back' : 'front']?.layers.find((l) => l.id === 's-title'))
const dark = (h) => /^#[0-9a-f]{6}$/i.test(h ?? '') && (0.299 * parseInt(h.slice(1, 3), 16) + 0.587 * parseInt(h.slice(3, 5), 16) + 0.114 * parseInt(h.slice(5, 7), 16)) < 128
const ink = computed(() => layer('s-title')?.color ?? layer('s-sub')?.color ?? otherTitle.value?.color ?? hex(dark(fabric.value) ? 'bone-0' : 'ink-950'))
/** Tulisan yang tenggelam di warna bahan (gelap di atas gelap / terang di atas terang) diganti warna kontras. */
const readable = (color, bg) => (/^#/.test(color ?? '') && /^#/.test(bg ?? '') && dark(color) === dark(bg) ? hex(dark(bg) ? 'bone-0' : 'ink-950') : color)
const applied = ref(null)
const uploading = ref(false)
const defaults = computed(() => ({ title: kit.value ? '{{brand}}' : '{{name}}', sub: kit.value ? '{{tagline}}' : '{{company}}' }))

/** Ganti lapisan s-* di sisi aktif dengan layout baru; isi teks & logo yang sudah ada dibawa. Elemen bebas dipertahankan. */
function apply(key) {
  const old = layer('s-logo')
  const c = {
    title: layer('s-title')?.text ?? otherTitle.value?.text ?? defaults.value.title,
    sub: layer('s-sub')?.text ?? defaults.value.sub,
    ink: readable(ink.value, fabric.value),
    logo: old && old.asset !== 'kit:logo' ? { asset: old.asset, url: old.url, px_w: old.px_w, px_h: old.px_h } : null,
  }
  side.value.layers = [...side.value.layers.filter((l) => !isSimple(l)), ...buildLayout(key, side.value.size, c)]
  ed.select([])
  ed.commit()
  applied.value = `${which.value}:${key}`
  toast.ok('Layout diterapkan. Urungkan dengan Ctrl+Z bila kurang cocok.')
}
/** Pratinjau kecil layout pada siluet produk (tanpa menyentuh desain). */
function previewSide(key) {
  return { ...side.value, layers: buildLayout(key, side.value.size, { title: layer('s-title')?.text ?? defaults.value.title, sub: layer('s-sub')?.text ?? defaults.value.sub, ink: ink.value }) }
}

// isi teks: ketik = ubah langsung (tanpa riwayat); selesai mengetik = satu langkah undo
function setText(ids, value) {
  ed.change(ids.map((l) => ({ id: l.id, layer: { ...l, text: value } })))
}
const title = computed({ get: () => layer('s-title')?.text ?? '', set: (v) => setText(titleLayers(side.value.layers), v) })
const sub = computed({ get: () => layer('s-sub')?.text ?? '', set: (v) => setText(side.value.layers.filter((l) => l.id === 's-sub'), v) })

async function uploadLogo(e) {
  const file = e.target.files?.[0]
  e.target.value = ''
  const l = layer('s-logo')
  if (!file || !l) return
  uploading.value = true
  try {
    const { data } = await studioApi.uploadImage(props.designId, file)
    ed.change([{ id: l.id, layer: { ...l, asset: data.asset, url: data.url, px_w: data.px_w, px_h: data.px_h } }], true)
    toast.ok('Logo dipasang.')
  } catch (err) {
    toast.error(err.message)
  } finally {
    uploading.value = false
  }
}
function useKitLogo() {
  const l = layer('s-logo')
  if (!kit.value?.logo) return toast.push('Unggah logo di tab Brand kit dulu, atau pakai "Unggah logo".')
  const { url, px_w, px_h, ...rest } = l // eslint-disable-line no-unused-vars
  ed.change([{ id: l.id, layer: { ...rest, asset: 'kit:logo' } }], true)
}

function setFabric(v) {
  for (const w of ['front', 'back']) {
    const s = ed.sides[w]
    if (!s) continue
    s.background = { ...s.background, type: 'color', value: v }
    // tulisan mode sederhana ikut disesuaikan agar tetap terbaca di bahan baru
    s.layers = s.layers.map((l) => (!isSimple(l) ? l : l.type === 'text' ? { ...l, color: readable(l.color, v) } : l.type === 'rect' ? { ...l, fill: readable(l.fill, v) } : l))
  }
  ed.commit()
}
function setInk(v) {
  const list = side.value.layers.filter((l) => isSimple(l))
  ed.change(list.map((l) => ({ id: l.id, layer: l.type === 'rect' ? { ...l, fill: v } : l.type === 'text' ? { ...l, color: v } : l })), true)
}
</script>

<template>
  <div class="grid gap-5">
    <section class="grid gap-2" aria-labelledby="sl-layout">
      <h2 id="sl-layout" class="flex items-center gap-2 text-base font-semibold"><LayoutTemplate class="size-5 text-accent-text" /> 1. Pilih layout{{ spec.print_sides === 2 ? ` · ${which === 'front' ? 'depan' : 'belakang'}` : '' }}</h2>
      <div class="grid grid-cols-2 gap-2">
        <button
          v-for="l in layouts" :key="l.key" type="button" :aria-pressed="applied === `${which}:${l.key}`"
          class="grid cursor-pointer content-start gap-1 rounded-md border border-line p-2 text-left hover:bg-surface-2 aria-pressed:border-accent aria-pressed:bg-surface-2"
          @click="apply(l.key)"
        >
          <span class="mx-auto block w-4/5"><MerchMockup :silhouette="spec.silhouette" :side="previewSide(l.key)" :which="which" :kit="kit" :vars="ed.vars.value" /></span>
          <span class="flex items-center gap-1 text-sm font-semibold"><Check v-if="applied === `${which}:${l.key}`" class="size-4 text-ok" /> {{ l.label }}</span>
          <span class="text-xs text-muted">{{ l.hint }}</span>
        </button>
      </div>
      <p v-if="spec.print_sides === 2" class="text-xs text-muted">Ganti sisi Depan/Belakang di bilah atas untuk mengatur sisi lainnya.</p>
    </section>

    <section class="grid gap-3" aria-labelledby="sl-content">
      <h2 id="sl-content" class="flex items-center gap-2 text-base font-semibold"><Sparkles class="size-5 text-accent-text" /> 2. Isi</h2>
      <p v-if="!hasSimple" class="rounded-md border border-dashed border-line-strong p-3 text-sm text-muted">Pilih layout di atas dulu — isinya bisa diubah di sini.</p>
      <template v-else>
        <AppField v-if="layer('s-title')" label="Teks utama" :hint="title.includes('{{') ? 'Teks {{…}} terisi otomatis dari brand kit / bio.' : ''">
          <AppInput v-model="title" maxlength="80" @change="ed.commit()" />
        </AppField>
        <AppField v-if="layer('s-sub')" label="Teks kedua"><AppInput v-model="sub" maxlength="80" @change="ed.commit()" /></AppField>
        <div v-if="layer('s-logo')" class="grid gap-2">
          <span class="text-sm font-medium text-muted">Logo</span>
          <div class="flex flex-wrap gap-2">
            <label :class="['inline-flex min-h-11 items-center gap-2 rounded-md border border-line-strong px-4 font-semibold hover:bg-surface-2', uploading ? 'pointer-events-none opacity-50' : 'cursor-pointer']">
              <ImageUp class="size-5" /> {{ uploading ? 'Mengunggah…' : 'Unggah logo' }}
              <input class="sr-only" type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" :disabled="uploading" @change="uploadLogo" />
            </label>
            <AppButton v-if="kit?.logo && layer('s-logo').asset !== 'kit:logo'" variant="ghost" @click="useKitLogo">Pakai logo brand kit</AppButton>
          </div>
          <span class="text-xs text-muted">PNG transparan ≥ 1000 px agar tajam saat dicetak besar.</span>
        </div>
      </template>
    </section>

    <section class="grid gap-3" aria-labelledby="sl-color">
      <h2 id="sl-color" class="text-base font-semibold">3. Warna</h2>
      <div class="grid gap-2">
        <span class="text-sm font-medium text-muted">Warna bahan</span>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="[name, label] in fabrics" :key="name" type="button" :title="label" :aria-label="`Bahan ${label}`" :aria-pressed="fabric === hex(name)"
            class="size-9 cursor-pointer rounded-full border border-line-strong aria-pressed:outline-2 aria-pressed:outline-offset-2 aria-pressed:outline-accent"
            :style="{ background: hex(name) }" @click="setFabric(hex(name))"
          ></button>
        </div>
      </div>
      <div v-if="hasSimple" class="grid gap-2">
        <span class="text-sm font-medium text-muted">Warna tulisan</span>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="[name, label] in inks" :key="name" type="button" :title="label" :aria-label="`Tulisan ${label}`" :aria-pressed="ink === hex(name)"
            class="size-9 cursor-pointer rounded-full border border-line-strong aria-pressed:outline-2 aria-pressed:outline-offset-2 aria-pressed:outline-accent"
            :style="{ background: hex(name) }" @click="setInk(hex(name))"
          ></button>
        </div>
      </div>
    </section>
    <p class="text-xs text-muted">Butuh lebih bebas? Tab <b>Edit</b> & <b>Lapisan</b> untuk menambah teks, bentuk, ikon, dan menggeser elemen.</p>
  </div>
</template>
