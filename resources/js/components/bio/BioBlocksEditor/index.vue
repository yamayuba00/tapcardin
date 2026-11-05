<script setup>
// Editor susunan blok satu halaman bio: tambah, urutkan (naik/turun), sembunyikan, hapus, dan isi per jenis blok.
// Perubahan baru tersimpan saat tombol Simpan editor ditekan; gambar galeri langsung diunggah agar bisa dipreview.
import { computed, ref } from 'vue'
import { ArrowDown, ArrowLeft, ArrowRight, ArrowUp, Eye, EyeOff, ImagePlus, LayoutList, Minus, Music, Plus, SquareMousePointer, Trash2, Type, Images, UserPlus, X } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppTextarea from '@/components/ui/AppTextarea/index.vue'
import { bioPagesApi } from '@/api/workspace'
import { BLOCK_TYPES, MAX_BLOCKS, MAX_IMAGES, SAVE_LABEL, SINGLE, newBlock, parseVideo } from '@/lib/bioBlocks'
import { useToastStore } from '@/stores/toast'

const blocks = defineModel({ type: Array, required: true })
const props = defineProps({ profileId: { type: String, required: true }, errors: { type: Object, default: () => ({}) } })
const toast = useToastStore()
const uploading = ref(null) // key blok galeri yang sedang mengunggah

const ICON = { save_contact: UserPlus, button: SquareMousePointer, text: Type, video: Music, gallery: Images, divider: Minus, contacts: LayoutList }
const META = Object.fromEntries(BLOCK_TYPES.map((t) => [t.type, t]))
const taken = computed(() => new Set(blocks.value.filter((b) => SINGLE.includes(b.type)).map((b) => b.type)))
const full = computed(() => blocks.value.length >= MAX_BLOCKS)
const PROVIDER = { youtube: 'YouTube', vimeo: 'Vimeo', tiktok: 'TikTok', spotify: 'Spotify' }

const err = (i, field) => props.errors[`blocks.${i}.data.${field}`]?.[0] ?? (field === 'type' ? props.errors[`blocks.${i}.type`]?.[0] : undefined)

function add(type) {
  if (full.value) return
  blocks.value = [...blocks.value, newBlock(type)]
}
function move(i, d) {
  const list = [...blocks.value]
  const j = i + d
  if (j < 0 || j >= list.length) return
  ;[list[i], list[j]] = [list[j], list[i]]
  blocks.value = list
}
function remove(i) {
  blocks.value = blocks.value.filter((_, k) => k !== i)
}

async function upload(b, e) {
  const files = [...(e.target.files ?? [])]
  e.target.value = ''
  const room = MAX_IMAGES - b.data.images.length
  if (files.length > room) toast.push(`Maksimal ${MAX_IMAGES} gambar per galeri — ${Math.max(room, 0)} gambar pertama yang diunggah.`)
  uploading.value = b.key
  try {
    for (const file of files.slice(0, Math.max(room, 0))) {
      const res = await bioPagesApi.image(props.profileId, file)
      b.data.images.push({ path: res.data.path, url: res.data.url, caption: '' })
    }
  } catch (x) {
    toast.error(x.fields?.image?.[0] ?? x.message ?? 'Gambar gagal diunggah.')
  } finally {
    uploading.value = null
  }
}
function moveImage(b, i, d) {
  const j = i + d
  if (j < 0 || j >= b.data.images.length) return
  const list = [...b.data.images]
  ;[list[i], list[j]] = [list[j], list[i]]
  b.data.images = list
}
</script>

<template>
  <div class="grid gap-4">
    <p v-if="!blocks.length" class="rounded-md border border-dashed border-line-strong p-5 text-center text-sm text-muted">
      Belum ada blok. Tambahkan dari pilihan di bawah — misalnya tombol "Ajak kolaborasi" atau video terbaru Anda.
    </p>

    <ol class="grid gap-3">
      <li v-for="(b, i) in blocks" :key="b.id || b.key" :class="['grid gap-4 rounded-md border bg-surface p-4', b.is_visible ? 'border-line' : 'border-dashed border-line-strong']">
        <div class="flex items-center gap-2">
          <component :is="ICON[b.type]" class="size-5 flex-none text-accent-text" aria-hidden="true" />
          <span class="min-w-0 flex-1 truncate font-semibold">{{ META[b.type].label }}<span v-if="!b.is_visible" class="font-normal text-muted"> · disembunyikan</span></span>
          <AppButton variant="ghost" icon :disabled="i === 0" :aria-label="`Naikkan blok ${i + 1}`" @click="move(i, -1)"><ArrowUp class="size-5" /></AppButton>
          <AppButton variant="ghost" icon :disabled="i === blocks.length - 1" :aria-label="`Turunkan blok ${i + 1}`" @click="move(i, 1)"><ArrowDown class="size-5" /></AppButton>
          <AppButton variant="ghost" icon :aria-label="b.is_visible ? 'Sembunyikan blok' : 'Tampilkan blok'" :aria-pressed="!b.is_visible" @click="b.is_visible = !b.is_visible">
            <component :is="b.is_visible ? Eye : EyeOff" class="size-5" />
          </AppButton>
          <AppButton variant="ghost" icon aria-label="Hapus blok" @click="remove(i)"><Trash2 class="size-5 text-danger" /></AppButton>
        </div>
        <p v-if="err(i, 'type')" class="text-sm text-danger" role="alert">{{ err(i, 'type') }}</p>

        <template v-if="b.type === 'button'">
          <div class="grid gap-4 sm:grid-cols-2">
            <AppField label="Teks tombol" :error="err(i, 'label')"><AppInput v-model="b.data.label" maxlength="60" placeholder="mis. Ajak Kolaborasi" :invalid="!!err(i, 'label')" /></AppField>
            <AppField label="Tautan" :error="err(i, 'url')"><AppInput v-model.trim="b.data.url" maxlength="500" inputmode="url" placeholder="https://… atau wa.me/62812…" :invalid="!!err(i, 'url')" /></AppField>
          </div>
          <AppSegmented v-model="b.data.style" label="Gaya tombol" :options="[{ value: 'primary', label: 'Menonjol' }, { value: 'secondary', label: 'Biasa' }]" />
        </template>

        <template v-else-if="b.type === 'text'">
          <AppField label="Judul (opsional)"><AppInput v-model="b.data.title" maxlength="80" placeholder="mis. Layanan saya" /></AppField>
          <AppField label="Teks" :hint="`${(b.data.body || '').length}/1000`" :error="err(i, 'body')"><AppTextarea v-model="b.data.body" maxlength="1000" rows="3" :invalid="!!err(i, 'body')" /></AppField>
          <AppSegmented v-model="b.data.align" label="Perataan" :options="[{ value: 'left', label: 'Kiri' }, { value: 'center', label: 'Tengah' }]" />
        </template>

        <template v-else-if="b.type === 'video'">
          <AppField label="Tautan video / musik" :error="err(i, 'url')"
            :hint="parseVideo(b.data.url) ? `Terdeteksi: ${PROVIDER[parseVideo(b.data.url).provider]}` : 'Tempel tautan YouTube, TikTok, Vimeo, atau Spotify.'">
            <AppInput v-model.trim="b.data.url" maxlength="500" inputmode="url" placeholder="https://youtu.be/…" :invalid="!!err(i, 'url') || (!!b.data.url && !parseVideo(b.data.url))" />
          </AppField>
        </template>

        <template v-else-if="b.type === 'gallery'">
          <AppSegmented v-model="b.data.layout" label="Tata letak galeri" :options="[{ value: 'grid', label: 'Grid' }, { value: 'carousel', label: 'Geser (carousel)' }]" />
          <ul v-if="b.data.images.length" class="grid gap-3 sm:grid-cols-2">
            <li v-for="(img, k) in b.data.images" :key="img.path" class="flex items-start gap-3 rounded-md border border-line p-2">
              <img :src="img.url" alt="" class="size-16 flex-none rounded-sm bg-surface-2 object-cover" />
              <div class="grid min-w-0 flex-1 gap-1">
                <AppInput v-model="img.caption" maxlength="80" placeholder="Keterangan (opsional)" :aria-label="`Keterangan gambar ${k + 1}`" />
                <div class="flex gap-1">
                  <AppButton variant="ghost" icon :disabled="k === 0" aria-label="Geser ke kiri" @click="moveImage(b, k, -1)"><ArrowLeft class="size-4" /></AppButton>
                  <AppButton variant="ghost" icon :disabled="k === b.data.images.length - 1" aria-label="Geser ke kanan" @click="moveImage(b, k, 1)"><ArrowRight class="size-4" /></AppButton>
                  <AppButton variant="ghost" icon aria-label="Hapus gambar" @click="b.data.images.splice(k, 1)"><X class="size-4" /></AppButton>
                </div>
              </div>
            </li>
          </ul>
          <p v-if="err(i, 'images')" class="text-sm text-danger" role="alert">{{ err(i, 'images') }}</p>
          <label
            v-if="b.data.images.length < MAX_IMAGES"
            :class="['inline-flex min-h-11 w-fit items-center gap-2 rounded-md border border-line-strong px-5 font-semibold hover:bg-surface-2', uploading === b.key ? 'pointer-events-none opacity-50' : 'cursor-pointer']"
          >
            <ImagePlus class="size-5" /> {{ uploading === b.key ? 'Mengunggah…' : 'Tambah gambar' }}
            <input class="sr-only" type="file" multiple accept="image/jpeg,image/png,image/webp" :disabled="uploading === b.key" @change="upload(b, $event)" />
          </label>
          <span class="text-sm text-muted">{{ b.data.images.length }}/{{ MAX_IMAGES }} gambar · JPG, PNG, atau WebP</span>
        </template>

        <template v-else-if="b.type === 'divider'">
          <AppSegmented v-model="b.data.style" label="Jenis pemisah" :options="[{ value: 'line', label: 'Garis' }, { value: 'space', label: 'Jarak kosong' }]" />
        </template>

        <template v-else-if="b.type === 'save_contact'">
          <AppField label="Teks tombol"><AppInput v-model="b.data.label" maxlength="40" :placeholder="SAVE_LABEL" /></AppField>
          <p class="text-sm text-muted">Pengunjung langsung diarahkan ke form "Tambah kontak" di HP-nya — nama, nomor, email, dan perusahaan sudah terisi. Sembunyikan blok ini bila bio ini tidak perlu tombol simpan.</p>
        </template>

        <p v-else-if="b.type === 'contacts'" class="text-sm text-muted">Menampilkan daftar dari bagian <b class="text-fg">Kontak & tautan</b> di bawah, dengan gaya "Tampilan tautan".</p>
      </li>
    </ol>

    <div class="grid gap-2">
      <span class="label">Tambah blok</span>
      <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
        <button
          v-for="t in BLOCK_TYPES"
          :key="t.type"
          type="button"
          class="grid cursor-pointer content-start gap-1 rounded-md border border-line p-3 text-left hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-50"
          :disabled="full || taken.has(t.type)"
          @click="add(t.type)"
        >
          <span class="flex items-center gap-2 font-semibold"><Plus class="size-4" aria-hidden="true" /><component :is="ICON[t.type]" class="size-4 text-accent-text" aria-hidden="true" /> {{ t.label }}</span>
          <span class="text-sm text-muted">{{ taken.has(t.type) ? 'Sudah ada di halaman ini' : t.hint }}</span>
        </button>
      </div>
      <p v-if="full" class="text-sm text-muted">Maksimal {{ MAX_BLOCKS }} blok per bio link.</p>
    </div>
  </div>
</template>
