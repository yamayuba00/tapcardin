<script setup>
// Pratinjau cetak: sisi depan & belakang tanpa garis bantu, dipotong ke ukuran jadi (kartu bersudut membulat).
// Unduh PNG resolusi cetak (± 300 dpi) — warna/font disematkan; tetap bukan file produksi (vendor memakai data desain).
import { ref } from 'vue'
import { Download } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import CardSide from '@/components/studio/CardSide/index.vue'
import LanyardStrip from '@/components/studio/LanyardStrip/index.vue'
import { download, svgToPng } from '@/lib/exportSvg'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  name: { type: String, default: 'desain' },
  sides: { type: Object, required: true }, // { front, back }
  kind: { type: String, default: 'card' },
  printSides: { type: Number, default: 2 },
  kit: { type: Object, default: null },
  vars: { type: Object, default: () => ({}) },
  qr: { type: Object, default: null },
})
const emit = defineEmits(['close'])
const toast = useToastStore()
const refs = { front: ref(null), back: ref(null) }
const busy = ref(null)
const DPI_MM = 300 / 25.4

async function save(which) {
  const el = refs[which].value?.querySelector('svg')
  if (!el) return
  busy.value = which
  try {
    const blob = await svgToPng(el, { widthPx: Math.round(props.sides[which].size.w * DPI_MM) })
    download(blob, `${props.name.replace(/[^\w-]+/g, '-').toLowerCase()}-${which === 'front' ? 'depan' : 'belakang'}.png`)
  } catch {
    toast.error('Gagal membuat PNG di browser ini.')
  } finally {
    busy.value = null
  }
}
const label = { front: 'Depan', back: 'Belakang' }
</script>

<template>
  <AppModal title="Pratinjau cetak" :description="kind === 'card' ? 'Ukuran jadi CR80 85,6 × 54 mm, tanpa garis bantu. Bleed 3 mm ikut terunduh di PNG.' : 'Satu pola tali; di produksi pola ini diulang sepanjang 90 cm.'" size="lg" @close="emit('close')">
    <div :class="['grid gap-6', kind === 'card' ? 'md:grid-cols-2' : '']">
      <figure v-for="w in (printSides === 2 ? ['front', 'back'] : ['front'])" :key="w" class="grid gap-2">
        <figcaption class="label">{{ label[w] }}</figcaption>
        <div :ref="refs[w]" :class="kind === 'card' ? 'overflow-hidden rounded-[14px] border border-line' : 'border border-line'">
          <CardSide :side="sides[w]" :kit="kit" :vars="vars" :qr="qr" />
        </div>
        <div v-if="kind === 'lanyard'" class="overflow-x-auto"><LanyardStrip :side="sides[w]" :kit="kit" :vars="vars" :length="450" :scale="1.6" /></div>
        <AppButton variant="secondary" :loading="busy === w" @click="save(w)"><Download class="size-4" /> Unduh PNG {{ label[w].toLowerCase() }}</AppButton>
      </figure>
    </div>
  </AppModal>
</template>
