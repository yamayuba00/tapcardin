<script setup>
// Mode sederhana editor kartu: ganti layout dari template TapCard (sesuai orientasi kartu) dan warna latar cepat.
// Nama, jabatan & kontak di kartu otomatis dari bio ({{name}} dst.), jadi pengguna cukup memilih tampilan.
// Elemen wajib (QR, NFC, TapCard) dibangun ulang server saat simpan.
import { computed, ref } from 'vue'
import { Check, LayoutTemplate, Palette, UserRound } from 'lucide-vue-next'
import CardSide from '@/components/studio/CardSide/index.vue'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  ed: { type: Object, required: true }, // useDesignEditor()
  orientation: { type: String, required: true },
})
const toast = useToastStore()
const ed = props.ed
const catalog = computed(() => ed.catalog.value)
const kit = computed(() => ed.kit.value)
const applied = ref(null)
const palette = computed(() => catalog.value?.palette ?? [])
const hex = (name) => palette.value.find((c) => c.name === name)?.hex
const BACKGROUNDS = [['bone-0', 'Putih'], ['bone-100', 'Krem'], ['onyx-950', 'Hitam'], ['onyx-800', 'Abu tua'], ['sky-700', 'Biru'], ['sage-700', 'Hijau'], ['coral-700', 'Merah'], ['champagne-300', 'Emas muda']]
const backgrounds = computed(() => BACKGROUNDS.filter(([n]) => hex(n)))
const templates = computed(() => (catalog.value?.templates ?? []).filter((t) => t.preview?.[props.orientation]))
const bg = computed(() => ed.side.value.background?.value)

/** Terapkan template ke kedua sisi (bisa diurungkan). */
function apply(t) {
  const p = t.preview[props.orientation]
  // salinan biasa (katalog bersifat reaktif → structuredClone tidak bisa menyalin Proxy)
  ed.sides.front = JSON.parse(JSON.stringify(p.front))
  ed.sides.back = JSON.parse(JSON.stringify(p.back))
  ed.select([])
  ed.commit()
  applied.value = t.key
  toast.ok(`Layout ${t.name} diterapkan. Urungkan dengan Ctrl+Z bila kurang cocok.`)
}
function setBg(v) {
  ed.setBackground({ ...ed.side.value.background, type: 'color', value: v })
}
</script>

<template>
  <div class="grid gap-5">
    <section class="grid gap-2" aria-labelledby="sc-layout">
      <h2 id="sc-layout" class="flex items-center gap-2 text-base font-semibold"><LayoutTemplate class="size-5 text-accent-text" /> 1. Pilih layout</h2>
      <div class="grid grid-cols-2 gap-2">
        <button
          v-for="t in templates" :key="t.key" type="button" :aria-pressed="applied === t.key"
          class="grid cursor-pointer content-start gap-1.5 rounded-md border border-line p-2 text-left hover:bg-surface-2 aria-pressed:border-accent aria-pressed:bg-surface-2"
          @click="apply(t)"
        >
          <span :class="['block overflow-hidden rounded-sm', orientation === 'portrait' ? 'mx-auto w-3/5' : '']">
            <CardSide :side="t.preview[orientation].front" :vars="ed.vars.value" :kit="kit" :qr="catalog.qr" trim />
          </span>
          <span class="flex items-center gap-1 text-sm font-semibold"><Check v-if="applied === t.key" class="size-4 text-ok" /> {{ t.name }}</span>
        </button>
      </div>
      <p class="text-xs text-muted">Mengganti layout menimpa kedua sisi kartu. Bisa diurungkan dengan Ctrl+Z.</p>
    </section>

    <section class="grid gap-2" aria-labelledby="sc-content">
      <h2 id="sc-content" class="flex items-center gap-2 text-base font-semibold"><UserRound class="size-5 text-accent-text" /> 2. Isi kartu</h2>
      <p class="text-sm text-muted">Nama, jabatan, perusahaan & kontak diambil otomatis dari bio kartu Anda — ubah di halaman Bio link. Logo & warna merek diatur sekali di tab <b>Brand kit</b>.</p>
    </section>

    <section class="grid gap-2" aria-labelledby="sc-color">
      <h2 id="sc-color" class="flex items-center gap-2 text-base font-semibold"><Palette class="size-5 text-accent-text" /> 3. Warna latar · {{ ed.active.value === 'front' ? 'depan' : 'belakang' }}</h2>
      <div class="flex flex-wrap gap-2">
        <button
          v-for="[name, label] in backgrounds" :key="name" type="button" :title="label" :aria-label="`Latar ${label}`" :aria-pressed="bg === hex(name)"
          class="size-9 cursor-pointer rounded-full border border-line-strong aria-pressed:outline-2 aria-pressed:outline-offset-2 aria-pressed:outline-accent"
          :style="{ background: hex(name) }" @click="setBg(hex(name))"
        ></button>
      </div>
      <p class="text-xs text-muted">Warna tulisan QR & NFC menyesuaikan latar secara otomatis. Butuh lebih bebas? Pakai tab <b>Edit bebas</b>.</p>
    </section>
  </div>
</template>
