<script setup>
// Editor desain lanyard (layar penuh). Yang diedit adalah satu pola (tile) yang diulang sepanjang tali 90 cm;
// alat sama dengan Studio kartu (teks, bentuk, ikon, logo, grup, ratakan, putar, kunci, undo/redo, zoom, grid & snap).
// Sinkron dengan kartu: brand kit bersama, kartu pasangan (slot lanyard kartu otomatis aktif), pratinjau gantung
// gabungan, dan salin elemen dua arah. Spesifikasi (lebar, pengait, sisi cetak) & harga dari katalog server.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft, CircleAlert, CircleCheck, CreditCard, Grid3x3, Magnet, Printer, Redo2, Trash2, Undo2 } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import ArrangeBar from '@/components/studio/ArrangeBar/index.vue'
import BrandKitPanel from '@/components/studio/BrandKitPanel/index.vue'
import CardSide from '@/components/studio/CardSide/index.vue'
import ColorPicker from '@/components/studio/ColorPicker/index.vue'
import EditorShell from '@/components/studio/EditorShell/index.vue'
import LanyardMockup from '@/components/studio/LanyardMockup/index.vue'
import LanyardSpecPanel from '@/components/studio/LanyardSpecPanel/index.vue'
import LanyardStrip from '@/components/studio/LanyardStrip/index.vue'
import LayerList from '@/components/studio/LayerList/index.vue'
import LayerProperties from '@/components/studio/LayerProperties/index.vue'
import PrintPreview from '@/components/studio/PrintPreview/index.vue'
import ToolRail from '@/components/studio/ToolRail/index.vue'
import ZoomControl from '@/components/studio/ZoomControl/index.vue'
import { studioApi } from '@/api/studio'
import { useDesignEditor } from '@/composables/useDesignEditor'
import { transplantLayers } from '@/lib/design'
import { useToastStore } from '@/stores/toast'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()
const id = route.params.id
const ed = useDesignEditor(id)
const { catalog, design, sides, side, active, selection, selected, issues, errors, saving, dirty, grid, snap, kit, vars } = ed

const tab = ref('spec')
const zoom = ref('fit')
const zoomer = ref(null)
const previewing = ref(false)
const confirmDelete = ref(false)
const cards = ref([])
onMounted(async () => { cards.value = (await studioApi.list('card').catch(() => ({ data: [] }))).data })

const spec = computed(() => design.value?.lanyard_spec ?? {})
const paired = computed(() => design.value?.paired ?? null)
const twoSided = computed(() => spec.value.print_sides === 2)
// tile ditampilkan selebar area kanvas; zoom memperbesar dari situ
const canvasStyle = computed(() => (zoom.value === 'fit' ? { width: '100%', maxWidth: '1100px' } : { width: `${900 * zoom.value}px` }))
const grouped = computed(() => selected.value.length > 1 && selected.value.every((l) => l.group && l.group === selected.value[0].group))
const anyLocked = computed(() => selected.value.some((l) => l.lock))
const cardOptions = computed(() => cards.value.map((c) => ({ value: c.id, label: c.name, description: c.paired && c.paired.id !== id ? 'sudah punya lanyard' : c.orientation === 'portrait' ? 'potret' : 'lanskap', disabled: !!(c.paired && c.paired.id !== id) })))
const TABS = computed(() => [
  { value: 'spec', label: 'Spesifikasi' },
  { value: 'props', label: 'Properti' },
  { value: 'layers', label: 'Lapisan' },
  { value: 'kit', label: 'Brand kit' },
  { value: 'preview', label: 'Pratinjau' },
  { value: 'issues', label: issues.value.length ? `Cek (${issues.value.length})` : 'Cek' },
])

async function setSpec(patch) {
  // geometri berubah (lebar / panjang pola) → server menormalkan ulang posisi; simpan dulu agar tidak hilang
  await ed.save(patch)
}
async function pairWith(cardId) {
  const res = await ed.save({ paired_design_id: cardId || null })
  if (res && cardId) toast.ok('Kartu dipasangkan. Lubang lanyard kartu diaktifkan & brand kit dipakai bersama.')
  cards.value = (await studioApi.list('card').catch(() => ({ data: cards.value }))).data
}
async function openCard() {
  if (dirty.value && !(await ed.save())) return
  router.push(`/designs/${paired.value.id}`)
}
/** Salin elemen terpilih ke sisi depan kartu pasangan (diskalakan agar muat safe zone kartu). */
async function copyToCard() {
  if (!paired.value) return toast.push('Pasangkan dengan kartu dulu di tab Spesifikasi.')
  const list = ed.editable.value
  if (!list.length) return
  try {
    const front = (await studioApi.get(paired.value.id)).data.front
    const copies = transplantLayers(list, front.size, { safe: front.zones?.safe ?? 4, vars: vars.value, existing: front.layers })
    const user = front.layers.filter((l) => !l.locked)
    await studioApi.save(paired.value.id, { front: { ...front, layers: [...user, ...copies] } })
    toast.ok(`${copies.length} elemen disalin ke kartu.`)
  } catch (e) { toast.error(e.message) }
}
async function destroy() {
  await studioApi.remove(id)
  dirty.value = false
  router.push('/designs?tab=lanyard')
}
function focusIssue(i) {
  ed.switchSide(i.side)
  ed.select(i.layer ? [i.layer] : [])
  tab.value = 'props'
}
function onKey(e) {
  if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName) || e.ctrlKey || e.metaKey || e.altKey) return
  const add = { t: 'text', r: 'rect', o: 'ellipse', l: 'line' }[e.key.toLowerCase()]
  if (add) { e.preventDefault(); ed.add(add); return }
  if (e.key === '+' || e.key === '=') { e.preventDefault(); zoomer.value?.by(1) }
  else if (e.key === '-' || e.key === '_') { e.preventDefault(); zoomer.value?.by(-1) }
  else if (e.key === '0') { e.preventDefault(); zoom.value = 'fit' }
}
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <div v-if="!catalog || !sides.front" class="grid gap-4 p-6" aria-busy="true"><AppSkeleton class="!h-14" /><AppSkeleton class="!h-[360px]" /></div>
  <EditorShell v-else :busy="saving">
    <template #top>
      <AppButton variant="ghost" icon aria-label="Kembali ke daftar desain" :to="{ path: '/designs', query: { tab: 'lanyard' } }"><ArrowLeft class="size-5" /></AppButton>
      <AppInput v-model="design.name" maxlength="120" aria-label="Nama desain lanyard" class="!min-h-10 max-w-56" @change="dirty = true" />
      <AppSegmented
        v-if="twoSided" :model-value="active" label="Sisi tali"
        :options="[{ value: 'front', label: 'Sisi depan' }, { value: 'back', label: 'Sisi belakang' }]" @update:model-value="ed.switchSide"
      />
      <span class="mx-1 h-6 w-px bg-line" aria-hidden="true"></span>
      <AppButton variant="ghost" icon aria-label="Urungkan" title="Urungkan (Ctrl+Z)" :disabled="!ed.canUndo.value" @click="ed.undo"><Undo2 class="size-5" /></AppButton>
      <AppButton variant="ghost" icon aria-label="Ulangi" title="Ulangi (Ctrl+Y)" :disabled="!ed.canRedo.value" @click="ed.redo"><Redo2 class="size-5" /></AppButton>
      <ZoomControl ref="zoomer" v-model="zoom" :levels="[1, 1.5, 2, 3, 4, 6]" />
      <AppButton variant="ghost" icon :aria-pressed="grid > 0" aria-label="Grid 5 mm" title="Grid 5 mm" :class="grid ? '!text-accent-text' : '!text-muted'" @click="grid = grid ? 0 : 5"><Grid3x3 class="size-5" /></AppButton>
      <AppButton variant="ghost" icon :aria-pressed="snap" aria-label="Snap" title="Snap (tahan Alt untuk bebas)" :class="snap ? '!text-accent-text' : '!text-muted'" @click="snap = !snap"><Magnet class="size-5" /></AppButton>
      <span class="hidden font-mono text-xs text-muted md:inline">{{ spec.width_mm }} mm × {{ spec.length_mm / 10 }} cm · pola {{ spec.tile_mm }} mm</span>
      <span class="ml-auto hidden text-sm text-muted sm:inline">{{ dirty ? 'Belum disimpan' : 'Tersimpan' }}</span>
      <AppButton v-if="paired" variant="ghost" :title="`Buka kartu ${paired.name}`" @click="openCard"><CreditCard class="size-5" /> Kartu</AppButton>
      <AppButton variant="ghost" icon aria-label="Pratinjau cetak" title="Pratinjau cetak & unduh PNG" @click="previewing = true"><Printer class="size-5" /></AppButton>
      <AppButton :loading="saving" @click="ed.save()">Simpan</AppButton>
    </template>

    <template #tools>
      <ToolRail :has-kit-logo="!!kit?.logo" :rings="false" @add="ed.add" @image="ed.addImage" @glyph="(n) => ed.add('glyph', { name: n })" @kit-logo="ed.addKitLogo" />
    </template>

    <template #canvas>
      <div class="sticky top-0 z-10 flex min-h-14 items-center justify-center p-2">
        <ArrangeBar
          v-if="selection.length && ed.editable.value.length"
          :count="selection.length" :grouped="grouped" :locked="anyLocked" :other-side="twoSided ? (active === 'front' ? 'belakang' : 'depan') : null" :pair-label="paired ? 'kartu' : null"
          @align="ed.align" @distribute="ed.distribute" @group="ed.group" @ungroup="ed.ungroup" @z="ed.zOrder" @lock="ed.toggleLock()"
          @other-side="ed.copyToOtherSide" @to-pair="copyToCard" @duplicate="ed.duplicate" @remove="ed.remove"
        />
        <p v-else class="text-xs text-muted">Desain satu pola — garis merah putus = batas pola yang diulang · biru = margin aman tepi tali {{ ed.safe.value }} mm</p>
      </div>
      <div class="grid gap-8 px-4 pt-6 pb-10 sm:px-10">
        <div class="mx-auto" :style="canvasStyle">
          <CardSide :side="side" :vars="vars" :kit="kit" :selection="selection" :grid="grid" :snap="snap" editable @select="ed.select" @change="ed.change" @commit="ed.commit" />
        </div>
        <div class="grid gap-2">
          <span class="label">Sepanjang tali (45 cm pertama)</span>
          <div class="overflow-x-auto rounded-md border border-line bg-surface p-3 scrollbar-none">
            <LanyardStrip :side="side" :kit="kit" :vars="vars" :length="450" :scale="2" />
          </div>
        </div>
      </div>
    </template>

    <template #panel>
      <div class="sticky top-0 z-10 border-b border-line bg-surface p-3">
        <AppSegmented v-model="tab" label="Panel" :options="TABS" class="flex-wrap" />
      </div>
      <div class="grid gap-4 p-4">
        <template v-if="tab === 'spec'">
          <AppField label="Kartu pasangan" hint="Kartu terpasang otomatis mendapat lubang lanyard & brand kit yang sama.">
            <AppSelect :model-value="paired?.id ?? ''" :options="cardOptions" searchable clearable placeholder="Belum dipasangkan" @update:model-value="pairWith" />
          </AppField>
          <LanyardSpecPanel :spec="spec" :catalog="catalog.lanyard" @change="setSpec" />
        </template>

        <template v-else-if="tab === 'props'">
          <LayerProperties v-if="selected.length" :layers="selected" :palette="catalog.palette" :fonts="catalog.fonts" :vars="vars" :kit="kit" @change="ed.patch" />
          <template v-else>
            <ColorPicker :model-value="side.background.value" :palette="catalog.palette" :kit="kit" label="Warna dasar tali" @update:model-value="(v) => ed.setBackground({ ...side.background, value: v })" />
            <p class="text-sm text-muted">Tips: pakai warna dari <em>Brand kit</em> agar tali selalu serasi dengan kartu. Teks <span class="font-mono" v-text="'{' + '{brand}' + '}'"></span> terisi nama merek.</p>
          </template>
        </template>

        <LayerList
          v-else-if="tab === 'layers'" :layers="side.layers" :selection="selection" :vars="vars"
          @select="(ids) => { ed.select(ids); tab = 'props' }" @reorder="ed.reorderTo" @toggle-hide="ed.toggleHide" @toggle-lock="ed.toggleLock"
        />

        <BrandKitPanel v-else-if="tab === 'kit'" :kit="kit" :kits="catalog.kits" :palette="catalog.palette" :paired-name="paired ? 'kartu pasangannya' : null" @use="ed.useKit" @changed="ed.kitChanged" />

        <template v-else-if="tab === 'preview'">
          <LanyardMockup :lanyard="sides.front" :back="twoSided ? sides.back : sides.front" :card="paired?.front" :kit="kit" :vars="vars" :qr="catalog.qr" :hardware="spec.hardware" :scale="1.7" />
          <AppButton v-if="paired" variant="secondary" :disabled="!ed.editable.value.length" @click="copyToCard">Salin elemen terpilih ke kartu</AppButton>
          <p v-else class="text-sm text-muted">Pasangkan kartu di tab Spesifikasi untuk melihat kartu menggantung di lanyard ini.</p>
        </template>

        <template v-else>
          <p v-if="!issues.length" class="flex items-center gap-2 text-sm text-ok"><CircleCheck class="size-5" /> Siap cetak. Simpan untuk memeriksa ulang.</p>
          <ul v-else class="grid gap-1">
            <li v-for="(i, n) in issues" :key="n">
              <button type="button" class="flex w-full cursor-pointer items-start gap-2 rounded-sm px-1 py-1.5 text-left text-sm hover:bg-surface-2" @click="focusIssue(i)">
                <CircleAlert :class="['mt-0.5 size-4 flex-none', i.level === 'error' ? 'text-danger' : 'text-warn']" />
                <span><span class="label !text-[10px]">{{ i.side === 'front' ? 'Depan' : 'Belakang' }}</span> {{ i.message }}</span>
              </button>
            </li>
          </ul>
          <p v-if="errors.length" class="text-xs text-danger">{{ errors.length }} masalah harus diperbaiki sebelum dicetak.</p>
          <AppButton variant="ghost" class="justify-self-start" @click="confirmDelete = true"><Trash2 class="size-5" /> Hapus desain lanyard</AppButton>
        </template>
      </div>
    </template>
  </EditorShell>

  <PrintPreview v-if="previewing" kind="lanyard" :print-sides="spec.print_sides" :name="design.name" :sides="sides" :kit="kit" :vars="vars" @close="previewing = false" />

  <AppModal v-if="confirmDelete" size="sm" title="Hapus desain lanyard?" :description="`“${design.name}” akan dihapus. Kartu pasangannya tetap ada.`" @close="confirmDelete = false">
    <template #footer>
      <AppButton variant="secondary" @click="confirmDelete = false">Batal</AppButton>
      <AppButton variant="danger" @click="destroy">Hapus</AppButton>
    </template>
  </AppModal>
</template>
