<script setup>
// U-12 / DBD §10.3 — Design Studio kartu (layar penuh): mulai dari template atau kartu kosong; teks, bentuk, ikon,
// gambar, logo brand kit; seleksi banyak, grup, ratakan/distribusi, putar, kunci/sembunyi, urutan lapisan, undo/redo,
// zoom, grid & snap, pratinjau cetak + PNG, simpan sebagai template, brand kit bersama, lanyard pasangan.
// Elemen wajib (QR, ikon NFC, zona TAP, TapCard) terkunci dan dibangun ulang server saat simpan.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import LeaveConfirm from '@/components/common/LeaveConfirm/index.vue'
import {
  ArrowLeft, CircleAlert, CircleCheck, Grid3x3, ImageUp, LayoutTemplate, Link2, Magnet, Printer, Redo2, ShoppingCart, Trash2, Undo2, X,
} from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppMenu from '@/components/ui/AppMenu/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import AppToggle from '@/components/ui/AppToggle/index.vue'
import ArrangeBar from '@/components/studio/ArrangeBar/index.vue'
import BrandKitPanel from '@/components/studio/BrandKitPanel/index.vue'
import CanvasLegend from '@/components/studio/CanvasLegend/index.vue'
import ColorPicker from '@/components/studio/ColorPicker/index.vue'
import EditorShell from '@/components/studio/EditorShell/index.vue'
import FlipCard from '@/components/studio/FlipCard/index.vue'
import LanyardMockup from '@/components/studio/LanyardMockup/index.vue'
import LayerList from '@/components/studio/LayerList/index.vue'
import LayerProperties from '@/components/studio/LayerProperties/index.vue'
import PrintPreview from '@/components/studio/PrintPreview/index.vue'
import SaveStatus from '@/components/studio/SaveStatus/index.vue'
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
async function saveAndLeave() {
  if (await ed.save()) ed.leaveGuard.leave()
  else ed.leaveGuard.stay()
}

const tab = ref('props')
const zoom = ref('fit')
const zoomer = ref(null)
const previewing = ref(false)
const ORDERED_MSG = 'Desain ini sudah dipesan sehingga tidak dapat dihapus. Gandakan desain bila ingin membuat versi baru.'
const confirmDelete = ref(false)
const savingTemplate = ref(false)
const templateName = ref('')
const pairing = ref(false)

const paired = computed(() => design.value?.paired ?? null)
const baseWidth = computed(() => (design.value?.orientation === 'portrait' ? 400 : 700))
const canvasStyle = computed(() => (zoom.value === 'fit' ? { maxWidth: `${baseWidth.value}px` } : { width: `${baseWidth.value * zoom.value}px` }))
const grouped = computed(() => selected.value.length > 1 && selected.value.every((l) => l.group && l.group === selected.value[0].group))
const anyLocked = computed(() => selected.value.some((l) => l.lock))
const otherSide = computed(() => (active.value === 'front' ? 'belakang' : 'depan'))
const TABS = computed(() => [
  { value: 'props', label: 'Edit' },
  { value: 'layers', label: 'Lapisan' },
  { value: 'kit', label: 'Brand kit' },
  { value: 'lanyard', label: 'Lanyard' },
  { value: 'issues', label: issues.value.length ? `Cek cetak (${issues.value.length})` : 'Cek cetak' },
])
// aksi yang jarang dipakai → menu "Lainnya" agar bilah atas tetap ringkas
const menu = computed(() => [
  { label: 'Pesan cetak kartu ini', icon: ShoppingCart, onSelect: orderPrint },
  { label: 'Pratinjau cetak & unduh PNG', icon: Printer, onSelect: () => (previewing.value = true) },
  { label: 'Simpan sebagai template', icon: LayoutTemplate, onSelect: () => (savingTemplate.value = true) },
  { label: 'Hapus desain', icon: Trash2, danger: true, onSelect: () => (design.value?.ordered ? toast.error(ORDERED_MSG) : (confirmDelete.value = true)) },
])

// ── latar ─────────────────────────────────────────────────
const bgInput = ref(null)
async function uploadBackground(e) {
  const f = e.target.files?.[0]
  e.target.value = ''
  if (!f) return
  try {
    const { data } = await studioApi.uploadImage(id, f)
    ed.setBackground({ type: 'image', asset: data.asset, url: data.url, value: side.value.background.value, fit: 'cover' })
  } catch (err) { toast.error(err.message) }
}

// ── lanyard pasangan ──────────────────────────────────────
async function openLanyard() {
  if (dirty.value && !(await ed.save())) return
  pairing.value = true
  try {
    const { data } = await studioApi.pairLanyard(id)
    dirty.value = false
    router.push(`/lanyards/${data.id}`)
  } catch (e) { toast.error(e.message) } finally { pairing.value = false }
}
/** Salin elemen terpilih ke pola lanyard pasangan (diskalakan agar muat lebar tali). */
async function copyToPair() {
  if (!paired.value) return toast.push('Buat lanyard pasangan dulu di tab Lanyard.')
  const list = ed.editable.value
  if (!list.length) return
  try {
    const front = (await studioApi.get(paired.value.id)).data.front
    const copies = transplantLayers(list, front.size, { safe: front.zones?.safe ?? 1.5, vars: vars.value, existing: front.layers })
    await studioApi.save(paired.value.id, { front: { ...front, layers: [...front.layers, ...copies] } })
    toast.ok(`${copies.length} elemen disalin ke lanyard.`)
  } catch (e) { toast.error(e.message) }
}

async function saveTemplate() {
  if (dirty.value && !(await ed.save())) return
  try {
    await studioApi.saveAsTemplate(id, templateName.value || null)
    toast.ok('Disimpan sebagai template. Pilih di "Template saya" saat membuat desain baru.')
    savingTemplate.value = false
  } catch (e) { toast.error(e.message) }
}
async function toggleLanyardSlot(v) {
  if (paired.value && !v) return toast.push('Kartu ini dipasangkan dengan lanyard, jadi lubang slot tetap aktif.')
  await ed.save({ lanyard: v })
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
  router.push('/designs')
}
/** Simpan dulu, lalu ke halaman pesan bila desain lolos cek cetak. */
async function orderPrint() {
  if (dirty.value && !(await ed.save())) return
  if (errors.value.length) {
    tab.value = 'issues'
    return toast.error('Perbaiki masalah merah di tab Cek cetak sebelum memesan.')
  }
  router.push({ path: '/orders/new', query: { design: id } })
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
  <div v-if="!catalog || !sides.front" class="grid gap-4 p-6" aria-busy="true"><AppSkeleton class="!h-14" /><AppSkeleton class="!h-[480px]" /></div>
  <EditorShell v-else :busy="saving">
    <template #top>
      <AppButton variant="ghost" icon aria-label="Kembali ke daftar desain" title="Kembali ke daftar desain" to="/designs"><ArrowLeft class="size-5" /></AppButton>
      <AppInput v-model="design.name" maxlength="120" aria-label="Nama desain" title="Nama desain (klik untuk mengubah)" class="!min-h-10 max-w-52 max-sm:min-w-0 max-sm:max-w-none max-sm:flex-1" @change="dirty = true" />
      <AppSegmented class="max-sm:order-1" :model-value="active" label="Sisi kartu" :options="[{ value: 'front', label: 'Depan' }, { value: 'back', label: 'Belakang' }]" @update:model-value="ed.switchSide" />
      <span class="mx-1 hidden h-6 w-px bg-line md:block" aria-hidden="true"></span>
      <div class="flex items-center max-sm:order-1">
        <AppButton variant="ghost" icon aria-label="Urungkan" title="Urungkan (Ctrl+Z)" :disabled="!ed.canUndo.value" @click="ed.undo"><Undo2 class="size-5" /></AppButton>
        <AppButton variant="ghost" icon aria-label="Ulangi" title="Ulangi (Ctrl+Y)" :disabled="!ed.canRedo.value" @click="ed.redo"><Redo2 class="size-5" /></AppButton>
      </div>
      <div class="flex items-center max-sm:hidden">
        <ZoomControl ref="zoomer" v-model="zoom" />
        <AppButton variant="ghost" icon :aria-pressed="grid > 0" aria-label="Tampilkan grid 5 mm" title="Grid 5 mm" :class="grid ? '!text-accent-text' : '!text-muted'" @click="grid = grid ? 0 : 5"><Grid3x3 class="size-5" /></AppButton>
        <AppButton variant="ghost" icon :aria-pressed="snap" aria-label="Tempel ke garis bantu" title="Tempel ke garis bantu (tahan Alt saat menggeser untuk bebas)" :class="snap ? '!text-accent-text' : '!text-muted'" @click="snap = !snap"><Magnet class="size-5" /></AppButton>
      </div>
      <div class="ml-auto flex items-center gap-2">
        <SaveStatus :dirty="dirty" :saving="saving" class="max-sm:hidden" />
        <AppButton variant="secondary" class="max-lg:!hidden" title="Lihat hasil cetak & unduh PNG" @click="previewing = true"><Printer class="size-5" /> Pratinjau cetak</AppButton>
        <AppMenu :items="menu" />
        <AppButton :loading="saving" title="Simpan (Ctrl+S)" @click="ed.save()">Simpan</AppButton>
      </div>
    </template>

    <template #tools>
      <ToolRail :has-kit-logo="!!kit?.logo" @add="ed.add" @image="ed.addImage" @glyph="(n) => ed.add('glyph', { name: n })" @kit-logo="ed.addKitLogo" />
    </template>

    <template #canvas>
      <div class="sticky top-0 z-10 flex min-h-14 items-center justify-center p-2 max-sm:min-h-0">
        <ArrangeBar
          v-if="selection.length && ed.editable.value.length"
          :count="selection.length" :grouped="grouped" :locked="anyLocked" :other-side="otherSide" :pair-label="paired ? 'lanyard' : null"
          @align="ed.align" @distribute="ed.distribute" @group="ed.group" @ungroup="ed.ungroup" @z="ed.zOrder" @lock="ed.toggleLock()"
          @other-side="ed.copyToOtherSide" @to-pair="copyToPair" @duplicate="ed.duplicate" @remove="ed.remove"
        />
        <p v-else class="rounded-full bg-surface px-3 py-1 text-xs text-muted max-sm:hidden">Klik elemen untuk mengedit · Shift+klik atau seret area untuk memilih beberapa sekaligus</p>
      </div>
      <div class="grid min-h-[calc(100%-56px)] place-items-center gap-4 px-4 pb-10 sm:px-10">
        <div :class="zoom === 'fit' ? 'w-full' : 'max-w-none'" :style="canvasStyle">
          <FlipCard
            :front="sides.front" :back="sides.back" :flipped="active === 'back'" :vars="vars" :kit="kit" :qr="catalog.qr"
            :selection="selection" :grid="grid" :snap="snap" editable
            @select="ed.select" @change="ed.change" @commit="ed.commit"
          />
        </div>
        <div class="grid max-w-2xl justify-items-center gap-2">
          <CanvasLegend :lanyard="design.lanyard" />
          <p class="hidden text-center text-xs text-muted sm:block">
            Pintasan: <kbd>T</kbd> teks · <kbd>R</kbd> kotak · <kbd>O</kbd> elips · <kbd>L</kbd> garis · panah geser 0,5 mm (Shift 2 mm) ·
            <kbd>Ctrl+D</kbd> duplikat · <kbd>Ctrl+G</kbd> kelompokkan · <kbd>+</kbd> <kbd>−</kbd> <kbd>0</kbd> zoom
          </p>
        </div>
      </div>
    </template>

    <template #panel>
      <div class="sticky top-0 z-10 border-b border-line bg-surface p-3">
        <AppSegmented v-model="tab" label="Panel" :options="TABS" class="flex-wrap" />
      </div>
      <div class="grid gap-4 p-4">
        <template v-if="tab === 'props'">
          <LayerProperties v-if="selected.length" :layers="selected" :palette="catalog.palette" :fonts="catalog.fonts" :vars="vars" :kit="kit" @change="ed.patch" />
          <template v-else>
            <div class="grid gap-1">
              <h2 class="text-base font-semibold">Pengaturan kartu</h2>
              <p class="text-sm text-muted">Belum ada elemen dipilih. Klik elemen di kartu untuk mengeditnya, atau tambahkan elemen dari bilah kiri.</p>
            </div>
            <ColorPicker :model-value="side.background.value" :palette="catalog.palette" :kit="kit" :label="`Warna latar sisi ${active === 'front' ? 'depan' : 'belakang'}`" @update:model-value="(v) => ed.setBackground({ ...side.background, value: v })" />
            <div class="grid gap-2">
              <span class="text-sm font-medium text-muted">Gambar latar sisi {{ active === 'front' ? 'depan' : 'belakang' }}</span>
              <div class="flex flex-wrap gap-2">
                <AppButton variant="secondary" @click="bgInput.click()"><ImageUp class="size-4" /> {{ side.background.type === 'image' ? 'Ganti gambar' : 'Unggah gambar' }}</AppButton>
                <AppButton v-if="side.background.type === 'image'" variant="ghost" @click="ed.setBackground({ type: 'color', value: side.background.value })"><X class="size-4" /> Hapus gambar</AppButton>
              </div>
              <input ref="bgInput" type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="uploadBackground" />
              <span class="text-xs text-muted">PNG, JPG, atau WebP. Gambar memenuhi kartu sampai area potong — pastikan QR tetap terbaca.</span>
            </div>
            <div class="grid gap-1 border-t border-line pt-4">
              <AppToggle :model-value="design.lanyard" @update:model-value="toggleLanyardSlot">Kartu dipakai dengan lanyard</AppToggle>
              <span class="text-xs text-muted">Menambahkan lubang slot di tengah atas. Teks & gambar akan diperingatkan bila masuk area lubang.</span>
            </div>
            <p class="border-t border-line pt-4 text-sm text-muted">
              Teks <span class="font-mono" v-text="'{' + '{name}' + '}'"></span>, <span class="font-mono" v-text="'{' + '{title}' + '}'"></span>, dan sejenisnya terisi otomatis dari bio kartu;
              <span class="font-mono" v-text="'{' + '{brand}' + '}'"></span> dari brand kit.
            </p>
          </template>
        </template>

        <LayerList
          v-else-if="tab === 'layers'" :layers="side.layers" :selection="selection" :vars="vars"
          @select="(ids) => { ed.select(ids); tab = 'props' }" @reorder="ed.reorderTo" @toggle-hide="ed.toggleHide" @toggle-lock="ed.toggleLock"
        />

        <BrandKitPanel v-else-if="tab === 'kit'" :kit="kit" :kits="catalog.kits" :palette="catalog.palette" :paired-name="paired ? 'lanyard pasangannya' : null" @use="ed.useKit" @changed="ed.kitChanged" />

        <template v-else-if="tab === 'lanyard'">
          <template v-if="paired">
            <p class="text-sm">Dipasangkan dengan lanyard <strong>{{ paired.name }}</strong>. Brand kit dipakai bersama dan lubang lanyard di kartu aktif otomatis.</p>
            <LanyardMockup :lanyard="paired.front" :back="paired.back" :card="sides.front" :kit="kit" :vars="vars" :qr="catalog.qr" :scale="1.7" />
            <div class="grid gap-2">
              <AppButton :loading="pairing" @click="openLanyard"><Link2 class="size-5" /> Buka editor lanyard</AppButton>
              <AppButton variant="secondary" :disabled="!ed.editable.value.length" title="Pilih elemen di kartu dulu" @click="copyToPair">Salin elemen terpilih ke lanyard</AppButton>
            </div>
          </template>
          <template v-else>
            <p class="text-sm text-muted">Buat tali lanyard yang serasi dengan kartu ini. Lanyard memakai brand kit kartu, dan lubang lanyard di kartu diaktifkan otomatis.</p>
            <AppButton :loading="pairing" @click="openLanyard"><Link2 class="size-5" /> Buat lanyard pasangan</AppButton>
          </template>
        </template>

        <template v-else>
          <p v-if="!issues.length" class="flex items-center gap-2 text-sm text-ok"><CircleCheck class="size-5" /> Siap cetak. Pemeriksaan diperbarui setiap kali disimpan.</p>
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
          <p v-if="errors.length" class="text-xs text-danger">{{ errors.length }} masalah merah harus diperbaiki sebelum kartu bisa dicetak. Yang kuning hanya saran.</p>
        </template>
      </div>
    </template>
  </EditorShell>

  <PrintPreview v-if="previewing" :name="design.name" :sides="sides" :kit="kit" :vars="vars" :qr="catalog.qr" @close="previewing = false" />

  <AppModal v-if="savingTemplate" size="sm" title="Simpan sebagai template" description="Template muncul di “Template saya” saat membuat kartu baru, jadi desain ini bisa dipakai ulang." @close="savingTemplate = false">
    <AppInput v-model="templateName" maxlength="120" :placeholder="`${design.name} (template)`" aria-label="Nama template" autofocus />
    <template #footer>
      <AppButton variant="secondary" @click="savingTemplate = false">Batal</AppButton>
      <AppButton @click="saveTemplate"><LayoutTemplate class="size-5" /> Simpan template</AppButton>
    </template>
  </AppModal>

  <AppModal v-if="confirmDelete" size="sm" title="Hapus desain?" :description="`Desain “${design.name}” akan dihapus permanen.${paired ? ' Lanyard pasangannya tetap ada.' : ''}`" @close="confirmDelete = false">
    <template #footer>
      <AppButton variant="secondary" @click="confirmDelete = false">Batal</AppButton>
      <AppButton variant="danger" @click="destroy">Hapus desain</AppButton>
    </template>
  </AppModal>

  <LeaveConfirm v-if="ed.leaveGuard.pending.value" what="perubahan desain" can-save :saving="saving" @stay="ed.leaveGuard.stay" @leave="ed.leaveGuard.leave" @save="saveAndLeave" />
</template>
