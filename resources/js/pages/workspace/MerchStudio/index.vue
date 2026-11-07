<script setup>
// Editor desain merchandise (layar penuh): jaket, kaos/baju, tote bag, mug, topi. Yang diedit adalah area cetak
// produk (mm, dari katalog server); alat sama dengan Studio kartu (teks, bentuk, ikon, gambar, logo brand kit, grup,
// ratakan, putar, kunci, undo/redo, zoom, grid & snap). Pratinjau siluet produk berwarna bahan.
// Pemesanan cetak merchandise belum dibuka ("Segera hadir") — desain bisa dibuat & disimpan lebih dulu.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import LeaveConfirm from '@/components/common/LeaveConfirm/index.vue'
import { ArrowLeft, CircleAlert, CircleCheck, Clock, Grid3x3, Magnet, Redo2, Trash2, Undo2 } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppMenu from '@/components/ui/AppMenu/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import ArrangeBar from '@/components/studio/ArrangeBar/index.vue'
import BrandKitPanel from '@/components/studio/BrandKitPanel/index.vue'
import CardSide from '@/components/studio/CardSide/index.vue'
import ColorPicker from '@/components/studio/ColorPicker/index.vue'
import EditorShell from '@/components/studio/EditorShell/index.vue'
import LayerList from '@/components/studio/LayerList/index.vue'
import LayerProperties from '@/components/studio/LayerProperties/index.vue'
import MerchMockup from '@/components/studio/MerchMockup/index.vue'
import SaveStatus from '@/components/studio/SaveStatus/index.vue'
import ToolRail from '@/components/studio/ToolRail/index.vue'
import ZoomControl from '@/components/studio/ZoomControl/index.vue'
import { studioApi } from '@/api/studio'
import { useDesignEditor } from '@/composables/useDesignEditor'
import { useToastStore } from '@/stores/toast'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()
const id = route.params.id
const ed = useDesignEditor(id)
const { catalog, design, sides, side, active, selection, selected, issues, errors, saving, dirty, grid, snap, kit, vars } = ed
async function saveAndLeave() {
  if (await ed.save()) ed.leaveGuard.leave()
  else ed.leaveGuard.stay()
}

const tab = ref('product')
const zoom = ref('fit')
const zoomer = ref(null)
const confirmDelete = ref(false)
const ORDERED_MSG = 'Desain ini sudah dipesan sehingga tidak dapat dihapus.'

const spec = computed(() => design.value?.merch_spec ?? {})
const twoSided = computed(() => spec.value.print_sides === 2)
// area cetak: lebar mengikuti kanvas (maks. ~560 px agar area tinggi tetap muat layar); zoom memperbesar dari ukuran mm
const canvasStyle = computed(() => {
  const s = side.value?.size
  if (!s) return {}
  if (zoom.value !== 'fit') return { width: `${s.w * 2 * zoom.value}px` }
  return { width: `min(100%, ${Math.round(Math.min(720, (560 * s.w) / s.h))}px)` }
})
const grouped = computed(() => selected.value.length > 1 && selected.value.every((l) => l.group && l.group === selected.value[0].group))
const anyLocked = computed(() => selected.value.some((l) => l.lock))
const TABS = computed(() => [
  { value: 'product', label: 'Produk' },
  { value: 'props', label: 'Edit' },
  { value: 'layers', label: 'Lapisan' },
  { value: 'kit', label: 'Brand kit' },
  { value: 'issues', label: issues.value.length ? `Cek cetak (${issues.value.length})` : 'Cek cetak' },
])
const menu = computed(() => [
  { label: 'Hapus desain', icon: Trash2, danger: true, onSelect: () => (design.value?.ordered ? toast.error(ORDERED_MSG) : (confirmDelete.value = true)) },
])

/** Warna bahan berlaku untuk kedua sisi produk. */
function setFabric(v) {
  for (const w of ['front', 'back']) if (sides[w]) sides[w].background = { ...sides[w].background, type: 'color', value: v }
  ed.commit()
}
async function destroy() {
  try {
    await studioApi.remove(id)
  } catch (e) {
    confirmDelete.value = false
    toast.error(e.message)
    return
  }
  dirty.value = false
  router.push('/designs?tab=merch')
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
      <AppButton variant="ghost" icon aria-label="Kembali ke daftar desain" title="Kembali ke daftar desain" :to="{ path: '/designs', query: { tab: 'merch' } }"><ArrowLeft class="size-5" /></AppButton>
      <AppInput v-model="design.name" maxlength="120" :aria-label="`Nama desain ${spec.name}`" title="Nama desain (klik untuk mengubah)" class="!min-h-10 max-w-52 max-sm:min-w-0 max-sm:max-w-none max-sm:flex-1" @change="dirty = true" />
      <AppSegmented
        class="max-sm:order-1"
        v-if="twoSided" :model-value="active" label="Sisi produk"
        :options="[{ value: 'front', label: 'Depan' }, { value: 'back', label: 'Belakang' }]" @update:model-value="ed.switchSide"
      />
      <span class="mx-1 hidden h-6 w-px bg-line md:block" aria-hidden="true"></span>
      <div class="flex items-center max-sm:order-1">
        <AppButton variant="ghost" icon aria-label="Urungkan" title="Urungkan (Ctrl+Z)" :disabled="!ed.canUndo.value" @click="ed.undo"><Undo2 class="size-5" /></AppButton>
        <AppButton variant="ghost" icon aria-label="Ulangi" title="Ulangi (Ctrl+Y)" :disabled="!ed.canRedo.value" @click="ed.redo"><Redo2 class="size-5" /></AppButton>
      </div>
      <div class="flex items-center max-sm:hidden">
        <ZoomControl ref="zoomer" v-model="zoom" :levels="[1, 1.5, 2, 3]" />
        <AppButton variant="ghost" icon :aria-pressed="grid > 0" aria-label="Tampilkan grid 10 mm" title="Grid 10 mm" :class="grid ? '!text-accent-text' : '!text-muted'" @click="grid = grid ? 0 : 10"><Grid3x3 class="size-5" /></AppButton>
        <AppButton variant="ghost" icon :aria-pressed="snap" aria-label="Tempel ke garis bantu" title="Tempel ke garis bantu (tahan Alt saat menggeser untuk bebas)" :class="snap ? '!text-accent-text' : '!text-muted'" @click="snap = !snap"><Magnet class="size-5" /></AppButton>
      </div>
      <span class="hidden rounded-full bg-surface-2 px-2.5 py-1 font-mono text-xs text-muted xl:inline">{{ spec.name }} · area cetak {{ spec.print_w_mm / 10 }} × {{ spec.print_h_mm / 10 }} cm · {{ spec.print_sides }} sisi</span>
      <div class="ml-auto flex items-center gap-2">
        <AppBadge tone="info" class="max-sm:!hidden">Cetak segera hadir</AppBadge>
        <SaveStatus :dirty="dirty" :saving="saving" class="max-sm:hidden" />
        <AppMenu :items="menu" />
        <AppButton :loading="saving" title="Simpan (Ctrl+S)" @click="ed.save()">Simpan</AppButton>
      </div>
    </template>

    <template #tools>
      <ToolRail :has-kit-logo="!!kit?.logo" :rings="false" @add="ed.add" @image="ed.addImage" @glyph="(n) => ed.add('glyph', { name: n })" @kit-logo="ed.addKitLogo" />
    </template>

    <template #canvas>
      <div class="sticky top-0 z-10 flex min-h-14 items-center justify-center p-2 max-sm:min-h-0">
        <ArrangeBar
          v-if="selection.length && ed.editable.value.length"
          :count="selection.length" :grouped="grouped" :locked="anyLocked" :other-side="twoSided ? (active === 'front' ? 'belakang' : 'depan') : null" :pair-label="null"
          @align="ed.align" @distribute="ed.distribute" @group="ed.group" @ungroup="ed.ungroup" @z="ed.zOrder" @lock="ed.toggleLock()"
          @other-side="ed.copyToOtherSide" @duplicate="ed.duplicate" @remove="ed.remove"
        />
        <p v-else class="rounded-full bg-surface px-3 py-1 text-xs text-muted max-sm:hidden">Anda mendesain <b class="text-fg">area cetak {{ spec.name?.toLowerCase() }}</b>{{ twoSided ? ` · sisi ${active === 'front' ? 'depan' : 'belakang'}` : '' }}. Klik elemen untuk mengedit.</p>
      </div>
      <div class="grid gap-8 px-4 pt-4 pb-10 sm:px-10 xl:grid-cols-[minmax(0,1fr)_340px] xl:items-start">
        <div class="grid gap-3">
          <div class="mx-auto" :style="canvasStyle">
            <CardSide :side="side" :vars="vars" :kit="kit" :selection="selection" :grid="grid" :snap="snap" editable @select="ed.select" @change="ed.change" @commit="ed.commit" />
          </div>
          <p class="flex items-center justify-center gap-1.5 text-xs text-muted">
            <span class="h-0 w-5 border-t border-dashed border-sky-300" aria-hidden="true"></span> Batas aman {{ ed.safe.value }} mm dari tepi area cetak
          </p>
        </div>
        <section class="grid gap-2" aria-labelledby="mockup-title">
          <h2 id="mockup-title" class="label">Pratinjau {{ spec.name?.toLowerCase() }}{{ twoSided ? ` · ${active === 'front' ? 'depan' : 'belakang'}` : '' }}</h2>
          <div class="rounded-md border border-line bg-surface p-4">
            <MerchMockup :silhouette="spec.silhouette" :side="side" :which="active" :kit="kit" :vars="vars" show-area />
          </div>
          <p class="text-xs text-muted">Siluet hanya ilustrasi. Ukuran cetak mengikuti area cetak {{ spec.print_w_mm / 10 }} × {{ spec.print_h_mm / 10 }} cm.</p>
        </section>
      </div>
    </template>

    <template #panel>
      <div class="sticky top-0 z-10 border-b border-line bg-surface p-3">
        <AppSegmented v-model="tab" label="Panel" :options="TABS" class="flex-wrap" />
      </div>
      <div class="grid gap-4 p-4">
        <template v-if="tab === 'product'">
          <div class="grid gap-1">
            <h2 class="text-base font-semibold">{{ spec.name }}</h2>
            <p class="text-sm text-muted">{{ spec.description }}</p>
          </div>
          <dl class="grid grid-cols-2 gap-3 text-sm">
            <div class="rounded-md bg-surface-2 p-3"><dt class="text-muted">Area cetak</dt><dd class="font-mono">{{ spec.print_w_mm / 10 }} × {{ spec.print_h_mm / 10 }} cm</dd></div>
            <div class="rounded-md bg-surface-2 p-3"><dt class="text-muted">Sisi cetak</dt><dd class="font-mono">{{ spec.print_sides === 2 ? 'Depan & belakang' : 'Satu sisi' }}</dd></div>
          </dl>
          <ColorPicker :model-value="sides.front.background.value" :palette="catalog.palette" :kit="kit" label="Warna bahan / produk" @update:model-value="setFabric" />
          <div class="flex items-start gap-3 rounded-md border border-line bg-surface-2 p-3 text-sm">
            <Clock class="mt-0.5 size-5 flex-none text-info" aria-hidden="true" />
            <p><b>Pemesanan cetak segera hadir.</b> Desain Anda tersimpan dan bisa langsung dipesan begitu layanan cetak {{ spec.name?.toLowerCase() }} dibuka.</p>
          </div>
        </template>

        <template v-else-if="tab === 'props'">
          <LayerProperties v-if="selected.length" :layers="selected" :palette="catalog.palette" :fonts="catalog.fonts" :vars="vars" :kit="kit" @change="ed.patch" />
          <div v-else class="grid gap-1">
            <h2 class="text-base font-semibold">Belum ada elemen dipilih</h2>
            <p class="text-sm text-muted">Klik elemen di area cetak untuk mengeditnya, atau tambahkan teks, bentuk, ikon, dan logo dari bilah kiri. Warna bahan diatur di tab <b>Produk</b>.</p>
          </div>
        </template>

        <LayerList
          v-else-if="tab === 'layers'" :layers="side.layers" :selection="selection" :vars="vars"
          @select="(ids) => { ed.select(ids); tab = 'props' }" @reorder="ed.reorderTo" @toggle-hide="ed.toggleHide" @toggle-lock="ed.toggleLock"
        />

        <BrandKitPanel v-else-if="tab === 'kit'" :kit="kit" :kits="catalog.kits" :palette="catalog.palette" :paired-name="null" @use="ed.useKit" @changed="ed.kitChanged" />

        <template v-else>
          <p v-if="!issues.length" class="flex items-center gap-2 text-sm text-ok"><CircleCheck class="size-5" /> Tidak ada masalah. Pemeriksaan diperbarui setiap kali disimpan.</p>
          <template v-else>
            <p class="text-sm text-muted">Klik masalah untuk langsung memilih elemennya.</p>
            <ul class="grid gap-1">
              <li v-for="(i, n) in issues" :key="n">
                <button type="button" class="flex w-full cursor-pointer items-start gap-2 rounded-sm px-1 py-1.5 text-left text-sm hover:bg-surface-2" @click="focusIssue(i)">
                  <CircleAlert :class="['mt-0.5 size-4 flex-none', i.level === 'error' ? 'text-danger' : 'text-warn']" />
                  <span><span class="label !text-[10px]">{{ i.side === 'front' ? 'Depan' : 'Belakang' }}</span> {{ i.message }}</span>
                </button>
              </li>
            </ul>
          </template>
          <p v-if="errors.length" class="text-xs text-danger">{{ errors.length }} masalah merah perlu diperbaiki sebelum dicetak. Yang kuning hanya saran.</p>
        </template>
      </div>
    </template>
  </EditorShell>

  <AppModal v-if="confirmDelete" size="sm" title="Hapus desain?" :description="`“${design.name}” akan dihapus permanen.`" @close="confirmDelete = false">
    <template #footer>
      <AppButton variant="secondary" @click="confirmDelete = false">Batal</AppButton>
      <AppButton variant="danger" @click="destroy">Hapus desain</AppButton>
    </template>
  </AppModal>

  <LeaveConfirm v-if="ed.leaveGuard.pending.value" what="perubahan desain" can-save :saving="saving" @stay="ed.leaveGuard.stay" @leave="ed.leaveGuard.leave" @save="saveAndLeave" />
</template>
