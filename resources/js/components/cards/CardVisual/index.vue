<script setup>
// Tampilan kartu sama seperti kartu fisik (desain + data bio kartu + QR asli), bisa dibalik dan diunduh sebagai
// gambar PNG: depan + belakang sekaligus, atau per sisi. Kartu digital boleh ganti desain (template / desain Studio).
import { computed, ref } from 'vue'
import { Download, Palette, Pencil, RefreshCw } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import CardSide from '@/components/studio/CardSide/index.vue'
import { cardsApi } from '@/api/workspace'
import { studioApi } from '@/api/studio'
import { useAction, useAsync } from '@/composables/useAsync'
import { cardsToPng, download } from '@/lib/exportSvg'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  token: { type: String, required: true },
  name: { type: String, default: 'kartu' }, // untuk nama berkas
  editable: Boolean, // tampilkan "Ganti desain" (kartu digital milik sendiri)
  compact: Boolean, // hanya kartu + balik (mis. di daftar kartu)
})
const emit = defineEmits(['changed'])
const toast = useToastStore()
const { data: v, reload } = useAsync(() => cardsApi.visual(props.token))
const flipped = ref(false)

// sudut membulat seperti kartu CR80 (3,18 mm)
const radius = computed(() => (v.value ? 3.18 / v.value.front.size.w : 0.037))
const corner = computed(() => ({ borderRadius: `${radius.value * 100}% / ${(radius.value * 100 * v.value.front.size.w) / v.value.front.size.h}%` }))

// ---- unduh PNG: render CardSide versi potong (tanpa bleed) di luar layar lalu gabungkan ----
const frontEl = ref(null)
const backEl = ref(null)
const exporting = ref(false)
const fileBase = computed(() => `kartu-${props.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'tapcard'}`)
async function save(which) {
  exporting.value = true
  try {
    const svgs = { both: [frontEl.value, backEl.value], front: [frontEl.value], back: [backEl.value] }[which].map((el) => el.querySelector('svg'))
    const blob = await cardsToPng(svgs, { widthPx: v.value.orientation === 'portrait' ? 1000 : 1600, radius: radius.value })
    download(blob, `${fileBase.value}${which === 'both' ? '' : which === 'front' ? '-depan' : '-belakang'}.png`)
    cardsApi.shared(props.token, 'download')
  } catch {
    toast.error('Gambar gagal dibuat. Coba lagi.')
  } finally {
    exporting.value = false
  }
}

// ---- ganti desain ----
const picking = ref(false)
const orientation = ref('landscape')
const catalog = ref(null)
const mine = ref([])
const { busy, run } = useAction()
async function openPicker() {
  picking.value = true
  orientation.value = v.value?.orientation ?? 'landscape'
  if (!catalog.value) {
    const [c, d] = await Promise.all([studioApi.catalog(), studioApi.list('card')])
    catalog.value = c.data
    mine.value = d.data
  }
}
const templates = computed(() => (catalog.value?.templates ?? []).filter((t) => t.key !== 'kosong'))
async function choose(body) {
  const res = await run(() => cardsApi.setDesign(props.token, body), 'Desain kartu diganti.').catch(() => null)
  if (!res) return
  v.value = res.data
  picking.value = false
  flipped.value = false
  emit('changed', res.data)
}
defineExpose({ reload })
</script>

<template>
  <div class="grid gap-4">
    <AppSkeleton v-if="!v" class="!aspect-[85.6/54] !h-auto w-full" />
    <template v-else>
      <!-- kartu bisa dibalik (klik / Enter) -->
      <button
        type="button" class="group mx-auto block w-full cursor-pointer [perspective:1600px]" :class="v.orientation === 'portrait' ? 'max-w-[300px]' : 'max-w-[520px]'"
        :aria-label="flipped ? 'Tampilkan sisi depan' : 'Tampilkan sisi belakang (QR)'" @click="flipped = !flipped"
      >
        <div :style="{ aspectRatio: `${v.front.size.w} / ${v.front.size.h}` }" class="relative w-full transition-transform duration-700 ease-[cubic-bezier(.2,.8,.2,1)] [transform-style:preserve-3d]" :class="flipped ? '[transform:rotateY(180deg)]' : ''">
          <div class="absolute inset-0 overflow-hidden border border-line [backface-visibility:hidden]" :style="corner">
            <CardSide :side="v.front" :vars="v.vars" :kit="v.kit" :qr="v.qr" trim />
          </div>
          <div class="absolute inset-0 overflow-hidden border border-line [backface-visibility:hidden] [transform:rotateY(180deg)]" :style="corner">
            <CardSide :side="v.back" :vars="v.vars" :kit="v.kit" :qr="v.qr" trim />
          </div>
        </div>
      </button>

      <div class="flex flex-wrap items-center justify-center gap-2">
        <AppButton variant="ghost" @click="flipped = !flipped"><RefreshCw class="size-4" /> {{ flipped ? 'Lihat depan' : 'Lihat belakang (QR)' }}</AppButton>
        <template v-if="!compact">
          <AppButton :loading="exporting" @click="save('both')"><Download class="size-5" /> Unduh gambar</AppButton>
          <AppButton variant="secondary" :disabled="exporting" @click="save('front')">Depan</AppButton>
          <AppButton variant="secondary" :disabled="exporting" @click="save('back')">Belakang</AppButton>
        </template>
      </div>
      <div v-if="editable && !compact" class="flex flex-wrap items-center justify-center gap-2 text-sm text-muted">
        <span>Desain: {{ v.design?.name ?? 'Onyx (bawaan)' }}</span>
        <AppButton variant="ghost" @click="openPicker"><Palette class="size-4" /> Ganti desain</AppButton>
        <AppButton v-if="v.design" variant="ghost" :to="`/designs/${v.design.id}`"><Pencil class="size-4" /> Edit di Studio</AppButton>
      </div>

      <!-- sumber ekspor: ukuran potong tanpa bleed, di luar layar -->
      <div class="pointer-events-none fixed -left-[10000px] top-0 w-[800px]" aria-hidden="true">
        <div ref="frontEl"><CardSide :side="v.front" :vars="v.vars" :kit="v.kit" :qr="v.qr" trim /></div>
        <div ref="backEl"><CardSide :side="v.back" :vars="v.vars" :kit="v.kit" :qr="v.qr" trim /></div>
      </div>
    </template>

    <AppModal v-if="picking" size="lg" title="Pilih desain kartu digital" description="Data diisi otomatis dari bio kartu ini; QR asli kartu ada di sisi belakang." @close="picking = false">
      <div class="grid gap-6">
        <AppSegmented v-model="orientation" label="Orientasi" :options="[{ value: 'landscape', label: 'Lanskap' }, { value: 'portrait', label: 'Potret' }]" />
        <AppSkeleton v-if="!catalog" class="!h-48" />
        <template v-else>
          <section class="grid gap-3">
            <h3 class="label">Template</h3>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
              <button
                v-for="t in templates" :key="t.key" type="button" :disabled="busy"
                class="grid cursor-pointer gap-2 rounded-md border border-line p-2 text-left text-sm hover:border-line-strong disabled:cursor-wait"
                @click="choose({ template: t.key, orientation })"
              >
                <div class="overflow-hidden rounded-sm"><CardSide :side="t.preview[orientation].front" :vars="catalog.variables" :qr="catalog.qr" trim /></div>
                <span class="font-medium">{{ t.name }}</span>
              </button>
            </div>
          </section>
          <section v-if="mine.length" class="grid gap-3">
            <h3 class="label">Desain saya (Studio)</h3>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
              <button
                v-for="d in mine" :key="d.id" type="button" :disabled="busy"
                class="grid cursor-pointer gap-2 rounded-md border p-2 text-left text-sm hover:border-line-strong disabled:cursor-wait" :class="v?.design?.id === d.id ? 'border-accent' : 'border-line'"
                @click="choose({ design: d.id })"
              >
                <div class="overflow-hidden rounded-sm"><CardSide :side="d.front" :vars="catalog.variables" :kit="d.kit" :qr="catalog.qr" trim /></div>
                <span class="truncate font-medium">{{ d.name }}</span>
              </button>
            </div>
          </section>
        </template>
      </div>
    </AppModal>
  </div>
</template>
