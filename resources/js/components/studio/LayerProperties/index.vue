<script setup>
// Panel properti elemen terpilih. Satu elemen: semua properti sesuai jenisnya. Banyak elemen: properti umum
// (transparansi, rotasi, warna isi). Elemen sistem (QR, NFC, TapCard) hanya ditampilkan sebagai info terkunci.
import { computed } from 'vue'
import { Lock } from 'lucide-vue-next'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppTextarea from '@/components/ui/AppTextarea/index.vue'
import AppToggle from '@/components/ui/AppToggle/index.vue'
import ColorPicker from '@/components/studio/ColorPicker/index.vue'
import { isSystem, layerLabel, textSize } from '@/lib/design'
import { GLYPHS } from '@/lib/glyphs'

const props = defineProps({
  layers: { type: Array, required: true }, // terpilih
  palette: { type: Array, required: true },
  fonts: { type: Object, required: true },
  vars: { type: Object, default: () => ({}) },
  kit: { type: Object, default: null },
})
const emit = defineEmits(['change'])

const layer = computed(() => (props.layers.length === 1 ? props.layers[0] : null))
const locked = computed(() => layer.value && isSystem(layer.value))
const set = (key, value) => emit('change', { [key]: value })
const num = (key, value, min = -Infinity, max = Infinity) => {
  const n = Number(value)
  if (!Number.isNaN(n)) set(key, Math.min(max, Math.max(min, n)))
}
const fontOptions = computed(() => Object.keys(props.fonts).map((f) => ({ value: f, label: f })))
const weightOptions = computed(() => (props.fonts[layer.value?.font] ?? [400]).map((w) => ({ value: w, label: String(w) })))
const glyphOptions = computed(() => Object.entries(GLYPHS).map(([name, icon]) => ({ value: name, label: name, icon })))
const varToken = (v) => '{' + '{' + v + '}' + '}'
const VARS = ['name', 'title', 'company', 'phone', 'email', 'website', 'brand', 'tagline']
const colorKey = computed(() => ({ rect: 'fill', ellipse: 'fill', polygon: 'fill', circle: 'stroke' })[layer.value?.type] ?? 'color')
const fitted = computed(() => (layer.value?.type === 'text' ? textSize(layer.value, props.vars) : null))
const hasBox = computed(() => ['rect', 'image', 'ellipse', 'polygon'].includes(layer.value?.type))
const hasStroke = computed(() => ['rect', 'ellipse', 'polygon'].includes(layer.value?.type))
const rotatable = computed(() => props.layers.every((l) => !['circle', 'rings', 'line'].includes(l.type)))
const multiFill = computed(() => props.layers.every((l) => ['rect', 'ellipse', 'polygon'].includes(l.type)))
const avg = (k, d) => Math.round((props.layers.reduce((s, l) => s + (l[k] ?? d), 0) / props.layers.length) * 100) / 100
const LOCK_INFO = {
  'sys-qr': 'QR code wajib ada agar kartu bisa di-scan ponsel tanpa NFC.',
  'sys-shortcode': 'Kode pendek untuk input manual di tap.domain.com/k.',
  'sys-brand': 'Nama aplikasi TapCard selalu tampil di sisi belakang.',
  'sys-nfc': 'Ikon NFC menunjukkan bagian yang ditempelkan ke ponsel.',
  'sys-tapzone': 'Zona TAP menandai letak chip NFC.',
  'sys-tapicon': 'Zona TAP menandai letak chip NFC.',
}
const n2 = 'grid grid-cols-2 gap-3'
</script>

<template>
  <div class="grid gap-4">
    <!-- banyak elemen -->
    <template v-if="!layer">
      <p class="text-sm"><strong>{{ layers.length }} elemen</strong> dipilih. Ubah bersama, atau atur posisi lewat bilah Ratakan di atas kanvas.</p>
      <AppField label="Transparansi"><input type="range" min="0.05" max="1" step="0.05" :value="avg('opacity', 1)" class="w-full cursor-pointer accent-[var(--accent)]" aria-label="Transparansi" @change="(e) => num('opacity', e.target.value, 0.05, 1)" /></AppField>
      <AppField v-if="rotatable" label="Rotasi (°)"><AppInput type="number" step="1" min="-180" max="180" :model-value="avg('rotate', 0)" @update:model-value="(v) => num('rotate', v, -180, 180)" /></AppField>
      <ColorPicker v-if="multiFill" :model-value="layers[0].fill || ''" :palette="palette" :kit="kit" label="Warna isi" @update:model-value="(v) => set('fill', v)" />
    </template>

    <template v-else>
      <span class="label truncate">{{ layerLabel(layer, vars) }}</span>

      <div v-if="locked" class="flex gap-3 rounded-md border border-line-strong bg-surface-2 p-3 text-sm">
        <Lock class="size-5 flex-none text-accent-text" />
        <span>{{ LOCK_INFO[layer.id] ?? 'Elemen wajib — tidak bisa dipindah, diubah, atau dihapus.' }} Posisi & warnanya diatur otomatis.</span>
      </div>

      <template v-else>
        <p v-if="layer.lock" class="flex items-center gap-2 rounded-md bg-surface-2 px-3 py-2 text-sm text-muted"><Lock class="size-4" /> Dikunci — tidak bisa digeser di kanvas (Ctrl+L untuk membuka).</p>

        <AppField label="Nama lapisan (opsional)"><AppInput :model-value="layer.name ?? ''" maxlength="40" placeholder="mis. Logo depan" @update:model-value="(v) => set('name', v || undefined)" /></AppField>

        <!-- teks -->
        <template v-if="layer.type === 'text'">
          <AppField label="Teks" hint="Enter = baris baru.">
            <AppTextarea :model-value="layer.text" rows="2" maxlength="300" @update:model-value="(v) => set('text', v)" />
          </AppField>
          <div class="flex flex-wrap gap-1.5">
            <button v-for="v in VARS" :key="v" type="button" class="cursor-pointer rounded-sm border border-line px-2 py-1 font-mono text-xs hover:bg-surface-2" @click="set('text', layer.text + varToken(v))" v-text="varToken(v)"></button>
          </div>
          <div :class="n2">
            <AppField label="Font"><AppSelect :model-value="layer.font" :options="fontOptions" @update:model-value="(v) => emit('change', { font: v, weight: fonts[v][0] })" /></AppField>
            <AppField label="Tebal"><AppSelect :model-value="layer.weight" :options="weightOptions" @update:model-value="(v) => num('weight', v)" /></AppField>
            <AppField label="Ukuran (pt)" :error="layer.size < 6 ? 'Minimal 6 pt' : ''"><AppInput type="number" step="0.5" min="4" :model-value="layer.size" @update:model-value="(v) => num('size', v, 4, 120)" /></AppField>
            <AppField label="Spasi huruf"><AppInput type="number" step="0.02" min="0" max="0.5" :model-value="layer.tracking ?? 0" @update:model-value="(v) => num('tracking', v, 0, 0.5)" /></AppField>
            <AppField v-if="layer.text.includes('\n')" label="Jarak baris"><AppInput type="number" step="0.05" min="0.8" max="3" :model-value="layer.lineHeight ?? 1.25" @update:model-value="(v) => num('lineHeight', v, 0.8, 3)" /></AppField>
            <AppField v-else label="Lebar maks (mm)" :hint="fitted !== layer.size ? `Diperkecil ke ${fitted} pt` : 'Kosong = bebas'">
              <AppInput type="number" step="1" min="5" :model-value="layer.fitWidth ?? ''" placeholder="—" @update:model-value="(v) => set('fitWidth', v === '' || Number(v) <= 0 ? undefined : Number(v))" />
            </AppField>
          </div>
          <AppSegmented :model-value="layer.align" label="Perataan" :options="[{ value: 'left', label: 'Kiri' }, { value: 'center', label: 'Tengah' }, { value: 'right', label: 'Kanan' }]" @update:model-value="(v) => set('align', v)" />
          <div class="flex flex-wrap gap-x-5">
            <AppToggle :model-value="!!layer.uppercase" @update:model-value="(v) => set('uppercase', v || undefined)">Kapital</AppToggle>
            <AppToggle :model-value="!!layer.italic" @update:model-value="(v) => set('italic', v || undefined)">Miring</AppToggle>
          </div>
        </template>

        <!-- ikon -->
        <AppField v-if="layer.type === 'glyph'" label="Ikon"><AppSelect :model-value="layer.name" :options="glyphOptions" searchable @update:model-value="(v) => set('name', v)" /></AppField>

        <!-- posisi & ukuran -->
        <div v-if="layer.type === 'line'" :class="n2">
          <AppField label="X1 (mm)"><AppInput type="number" step="0.5" :model-value="layer.x1" @update:model-value="(v) => num('x1', v)" /></AppField>
          <AppField label="Y1 (mm)"><AppInput type="number" step="0.5" :model-value="layer.y1" @update:model-value="(v) => num('y1', v)" /></AppField>
          <AppField label="X2 (mm)"><AppInput type="number" step="0.5" :model-value="layer.x2" @update:model-value="(v) => num('x2', v)" /></AppField>
          <AppField label="Y2 (mm)"><AppInput type="number" step="0.5" :model-value="layer.y2" @update:model-value="(v) => num('y2', v)" /></AppField>
          <AppField label="Tebal (mm)"><AppInput type="number" step="0.05" min="0.1" :model-value="layer.width" @update:model-value="(v) => num('width', v, 0.1, 5)" /></AppField>
          <AppToggle :model-value="!!layer.dash" class="self-end" @update:model-value="(v) => set('dash', v || undefined)">Putus-putus</AppToggle>
        </div>
        <div v-else-if="layer.type === 'circle' || layer.type === 'rings'" :class="n2">
          <AppField label="Pusat X (mm)"><AppInput type="number" step="0.5" :model-value="layer.cx" @update:model-value="(v) => num('cx', v)" /></AppField>
          <AppField label="Pusat Y (mm)"><AppInput type="number" step="0.5" :model-value="layer.cy" @update:model-value="(v) => num('cy', v)" /></AppField>
          <template v-if="layer.type === 'rings'">
            <AppField label="Dari r (mm)"><AppInput type="number" step="1" :model-value="layer.from" @update:model-value="(v) => num('from', v, 1, 80)" /></AppField>
            <AppField label="Sampai r (mm)"><AppInput type="number" step="1" :model-value="layer.to" @update:model-value="(v) => num('to', v, 2, 120)" /></AppField>
            <AppField label="Jarak (mm)"><AppInput type="number" step="1" min="1" :model-value="layer.step" @update:model-value="(v) => num('step', v, 1, 30)" /></AppField>
            <AppField label="Opasitas"><AppInput type="number" step="0.05" min="0.05" max="1" :model-value="layer.opacity" @update:model-value="(v) => num('opacity', v, 0.05, 1)" /></AppField>
          </template>
          <template v-else>
            <AppField label="Jari-jari (mm)"><AppInput type="number" step="0.5" :model-value="layer.r" @update:model-value="(v) => num('r', v, 0.5, 60)" /></AppField>
            <AppField label="Tebal garis (mm)"><AppInput type="number" step="0.05" :model-value="layer.strokeWidth" @update:model-value="(v) => num('strokeWidth', v, 0.1, 5)" /></AppField>
          </template>
        </div>
        <div v-else :class="n2">
          <AppField label="X (mm)"><AppInput type="number" step="0.5" :model-value="layer.x" @update:model-value="(v) => num('x', v)" /></AppField>
          <AppField label="Y (mm)"><AppInput type="number" step="0.5" :model-value="layer.y" @update:model-value="(v) => num('y', v)" /></AppField>
          <template v-if="hasBox">
            <AppField label="Lebar (mm)"><AppInput type="number" step="0.5" min="0.2" :model-value="layer.w" @update:model-value="(v) => num('w', v, 0.2, 400)" /></AppField>
            <AppField label="Tinggi (mm)"><AppInput type="number" step="0.5" min="0.2" :model-value="layer.h" @update:model-value="(v) => num('h', v, 0.2, 400)" /></AppField>
          </template>
          <template v-if="layer.type === 'glyph'">
            <AppField label="Ukuran (mm)"><AppInput type="number" step="0.5" min="2" :model-value="layer.size" @update:model-value="(v) => num('size', v, 2, 60)" /></AppField>
            <AppField label="Tebal garis"><AppInput type="number" step="0.25" min="1" max="3" :model-value="layer.strokeWidth ?? 2" @update:model-value="(v) => num('strokeWidth', v, 1, 3)" /></AppField>
          </template>
          <AppField v-if="layer.type === 'rect'" label="Sudut (mm)"><AppInput type="number" step="0.5" min="0" :model-value="layer.rx ?? 0" @update:model-value="(v) => num('rx', v, 0, 50)" /></AppField>
          <template v-if="layer.type === 'polygon'">
            <AppField label="Jumlah sisi"><AppInput type="number" step="1" min="3" max="12" :model-value="layer.sides" @update:model-value="(v) => num('sides', v, 3, 12)" /></AppField>
            <AppField v-if="layer.star" label="Kedalaman"><AppInput type="number" step="0.05" min="0.2" max="0.9" :model-value="layer.inner ?? 0.5" @update:model-value="(v) => num('inner', v, 0.2, 0.9)" /></AppField>
          </template>
        </div>
        <AppToggle v-if="layer.type === 'polygon'" :model-value="!!layer.star" @update:model-value="(v) => set('star', v)">Bentuk bintang</AppToggle>

        <!-- gambar -->
        <template v-if="layer.type === 'image'">
          <AppSegmented :model-value="layer.fit ?? 'contain'" label="Isi kotak" :options="[{ value: 'contain', label: 'Utuh' }, { value: 'cover', label: 'Penuhi (crop)' }]" @update:model-value="(v) => set('fit', v)" />
          <AppSegmented :model-value="layer.mask ?? 'none'" label="Bentuk" :options="[{ value: 'none', label: 'Asli' }, { value: 'rounded', label: 'Membulat' }, { value: 'circle', label: 'Lingkaran' }]" @update:model-value="(v) => set('mask', v === 'none' ? undefined : v)" />
          <AppField v-if="layer.mask === 'rounded'" label="Radius sudut (mm)"><AppInput type="number" step="0.5" min="0.5" :model-value="layer.radius ?? 2" @update:model-value="(v) => num('radius', v, 0.5, 30)" /></AppField>
          <p class="text-sm text-muted">
            {{ layer.asset === 'kit:logo' ? 'Logo dari brand kit — ganti logo di panel Brand kit, semua desain ikut berubah.' : 'Disarankan ≥ 300 dpi pada ukuran cetak. Server memeriksa resolusi saat disimpan.' }}
          </p>
        </template>

        <!-- warna -->
        <ColorPicker
          v-if="layer.type !== 'image'"
          :model-value="layer[colorKey] ?? ''" :palette="palette" :kit="kit" :allow-none="hasStroke"
          :label="hasStroke ? 'Warna isi' : 'Warna'" @update:model-value="(v) => set(colorKey, v)"
        />
        <template v-if="hasStroke">
          <AppToggle :model-value="!!layer.stroke" @update:model-value="(v) => emit('change', v ? { stroke: layer.fill === 'none' ? (kit ? 'kit:text' : palette[0].hex) : layer.fill, strokeWidth: 0.3 } : { stroke: undefined, strokeWidth: undefined })">Garis tepi</AppToggle>
          <template v-if="layer.stroke">
            <ColorPicker :model-value="layer.stroke" :palette="palette" :kit="kit" label="Warna garis tepi" @update:model-value="(v) => set('stroke', v)" />
            <AppField label="Tebal garis tepi (mm)"><AppInput type="number" step="0.05" min="0.1" :model-value="layer.strokeWidth ?? 0.3" @update:model-value="(v) => num('strokeWidth', v, 0.1, 5)" /></AppField>
          </template>
        </template>

        <!-- umum -->
        <div :class="n2">
          <AppField v-if="!['circle', 'rings', 'line'].includes(layer.type)" label="Rotasi (°)"><AppInput type="number" step="1" min="-180" max="180" :model-value="layer.rotate ?? 0" @update:model-value="(v) => num('rotate', v, -180, 180)" /></AppField>
          <AppField v-if="layer.type !== 'rings'" label="Transparansi">
            <input type="range" min="0.05" max="1" step="0.05" :value="layer.opacity ?? 1" class="mt-3 w-full cursor-pointer accent-[var(--accent)]" aria-label="Transparansi" @input="(e) => num('opacity', e.target.value, 0.05, 1)" />
          </AppField>
        </div>
        <AppToggle v-if="['text', 'image'].includes(layer.type)" :model-value="!!layer.allowBleed" @update:model-value="(v) => set('allowBleed', v || undefined)">Boleh melewati tepi (sengaja terpotong)</AppToggle>
        <p v-if="layer.group" class="text-xs text-muted">Bagian dari grup — klik elemen mana pun di grup memilih semuanya. Ctrl+Shift+G untuk memisah.</p>
      </template>
    </template>
  </div>
</template>
