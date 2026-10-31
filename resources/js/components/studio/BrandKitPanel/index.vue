<script setup>
// Brand kit bersama: warna utama/sekunder/latar/teks, logo, nama merek, tagline, website. Elemen yang memakai
// `kit:*`, logo brand kit, atau {{brand}} {{tagline}} ikut berubah di SEMUA desain yang memakai kit ini (kartu & lanyard).
import { computed, ref, watch } from 'vue'
import { ImageUp, Plus } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import ColorPicker from '@/components/studio/ColorPicker/index.vue'
import { kitsApi } from '@/api/studio'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  kit: { type: Object, default: null }, // kit desain ini
  kits: { type: Array, default: () => [] },
  palette: { type: Array, required: true },
  pairedName: { type: String, default: null },
})
const emit = defineEmits(['use', 'changed'])
const toast = useToastStore()
const busy = ref(false)
const form = ref(null)
watch(() => props.kit, (k) => (form.value = k ? JSON.parse(JSON.stringify(k)) : null), { immediate: true })

const options = computed(() => props.kits.map((k) => ({ value: k.id, label: k.name, description: k.tagline || undefined })))
const COLORS = [['primary', 'Warna utama'], ['secondary', 'Warna sekunder / aksen'], ['background', 'Warna terang / latar'], ['text', 'Warna teks']]

async function create() {
  busy.value = true
  try {
    const hex = (n) => props.palette.find((c) => c.name === n)?.hex
    const { data } = await kitsApi.create({ name: 'Merek baru', colors: { primary: hex('onyx-950'), secondary: hex('champagne-500'), background: hex('bone-50'), text: hex('ink-950') } })
    emit('changed', data)
    emit('use', data.id)
  } catch (e) { toast.error(e.message) } finally { busy.value = false }
}
let t = null
function save(patch) {
  Object.assign(form.value, patch)
  emit('changed', { ...form.value }) // pratinjau langsung
  clearTimeout(t)
  t = setTimeout(async () => {
    try {
      const { data } = await kitsApi.update(form.value.id, { name: form.value.name, colors: form.value.colors, display_name: form.value.display_name, tagline: form.value.tagline, website: form.value.website })
      emit('changed', data)
    } catch (e) { toast.error(e.message) }
  }, 500)
}
const setColor = (k, v) => save({ colors: { ...form.value.colors, [k]: v } })
async function upload(e) {
  const f = e.target.files?.[0]
  e.target.value = ''
  if (!f) return
  busy.value = true
  try { emit('changed', (await kitsApi.logo(form.value.id, f)).data) } catch (err) { toast.error(err.message) } finally { busy.value = false }
}
</script>

<template>
  <div class="grid gap-4">
    <p class="text-sm text-muted">
      Atur warna & logo merek sekali — kartu{{ pairedName ? ` dan ${pairedName}` : ' dan lanyard' }} yang memakai brand kit ini ikut berubah otomatis.
      Pilih warna bertanda <em>Brand kit</em> di panel properti agar elemen mengikuti kit.
    </p>
    <div class="flex items-end gap-2">
      <AppField label="Brand kit desain ini" class="min-w-0 flex-1">
        <AppSelect :model-value="kit?.id ?? ''" :options="options" placeholder="Tanpa brand kit" clearable @update:model-value="(v) => emit('use', v || null)" />
      </AppField>
      <AppButton variant="secondary" icon aria-label="Buat brand kit baru" title="Buat brand kit baru" :loading="busy" @click="create"><Plus class="size-5" /></AppButton>
    </div>

    <template v-if="form">
      <AppField label="Nama kit"><AppInput :model-value="form.name" maxlength="80" @update:model-value="(v) => save({ name: v })" /></AppField>
      <AppField label="Nama merek" hint="Dipakai variabel {{brand}}."><AppInput :model-value="form.display_name ?? ''" maxlength="120" @update:model-value="(v) => save({ display_name: v || null })" /></AppField>
      <AppField label="Tagline" hint="Variabel {{tagline}}."><AppInput :model-value="form.tagline ?? ''" maxlength="160" @update:model-value="(v) => save({ tagline: v || null })" /></AppField>
      <AppField label="Website"><AppInput :model-value="form.website ?? ''" maxlength="160" placeholder="merek.com" @update:model-value="(v) => save({ website: v || null })" /></AppField>

      <div class="grid gap-2">
        <span class="text-sm font-medium text-muted">Logo</span>
        <div class="flex items-center gap-3">
          <span class="grid h-16 w-28 place-items-center overflow-hidden rounded-sm border border-line bg-surface-2">
            <img v-if="form.logo" :src="form.logo.url" alt="Logo merek" class="max-h-full max-w-full object-contain" />
            <span v-else class="text-xs text-muted">Belum ada</span>
          </span>
          <label class="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-md border border-line-strong px-4 text-sm font-semibold hover:bg-surface-2">
            <ImageUp class="size-4" /> {{ form.logo ? 'Ganti' : 'Unggah' }}
            <input class="sr-only" type="file" accept="image/png,image/jpeg,image/webp" :disabled="busy" @change="upload" />
          </label>
        </div>
        <span class="text-xs text-muted">PNG transparan ≥ 1000 px agar tajam di kartu & lanyard.</span>
      </div>

      <ColorPicker v-for="[k, label] in COLORS" :key="k" :model-value="form.colors[k]" :palette="palette" :allow-kit="false" :label="label" @update:model-value="(v) => setColor(k, v)" />
    </template>
  </div>
</template>
