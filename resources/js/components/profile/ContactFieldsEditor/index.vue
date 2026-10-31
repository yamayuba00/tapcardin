<script setup>
// Editor kontak & tautan bio link. Isian menyesuaikan jenis (contact_types.input):
// phone/wallet = nomor · handle = @username (boleh tempel tautan profil) · url · text · bank (bank + nomor + pemilik) · image (QRIS).
import { computed, ref } from 'vue'
import { ArrowDown, ArrowUp, ImageUp, Lock, Plus, X } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppCheckbox from '@/components/ui/AppCheckbox/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppTextarea from '@/components/ui/AppTextarea/index.vue'
import ContactTypePicker from '@/components/profile/ContactTypePicker/index.vue'
import { profilesApi } from '@/api/workspace'
import { contactIcon } from '@/lib/contactIcons'
import { handleOf, hrefOf } from '@/lib/contacts'
import { useToastStore } from '@/stores/toast'

const fields = defineModel({ type: Array, required: true })
const props = defineProps({
  catalog: { type: Object, required: true }, // { types: [...], banks: [...] }
  profileId: { type: String, required: true },
  errors: { type: Object, default: () => ({}) },
})
const toast = useToastStore()
const picking = ref(false)
const uploading = ref(null)

const byKey = computed(() => Object.fromEntries(props.catalog.types.map((t) => [t.key, t])))
const typeOptions = computed(() => props.catalog.types.map((t) => ({ value: t.key, label: t.label, group: t.group_name, icon: contactIcon(t.icon) })))
const bankOptions = computed(() => props.catalog.banks.map((b) => ({ value: b, label: b })))
const PRIMARY_TYPES = ['phone', 'whatsapp', 'whatsapp_business', 'email']

function add(t) {
  picking.value = false
  fields.value.push({
    id: null, type: t.key, label: '', value: '', meta: t.input === 'bank' ? { bank: '', holder: '' } : {},
    is_primary: PRIMARY_TYPES.includes(t.key) && !fields.value.some((f) => f.type === t.key),
    is_visible: true, in_vcard: t.vcard !== 'none', is_locked: false, image_url: null,
  })
}
function changeType(f, key) {
  const t = byKey.value[key]
  f.type = key
  if (t.input === 'bank' && !f.meta?.bank) f.meta = { bank: '', holder: '' }
  if (t.vcard === 'none') f.in_vcard = false
}
function move(i, d) {
  const j = i + d
  if (j < 0 || j >= fields.value.length) return
  const arr = fields.value
  ;[arr[i], arr[j]] = [arr[j], arr[i]]
}
async function upload(f, e) {
  const file = e.target.files?.[0]
  e.target.value = ''
  if (!file) return
  uploading.value = f
  try {
    const { data } = await profilesApi.fieldImage(props.profileId, file)
    f.value = data.path
    f.image_url = data.url
  } catch (err) {
    toast.error(err.message)
  } finally {
    uploading.value = null
  }
}
const err = (i, k = 'value') => props.errors[`fields.${i}.${k}`]?.[0]
const preview = (f) => {
  const t = byKey.value[f.type]
  return t && t.input === 'handle' && f.value ? hrefOf(t, f.value) : null
}
</script>

<template>
  <div class="grid gap-3">
    <div v-for="(f, i) in fields" :key="f.id || 'n' + i" class="grid gap-3 rounded-md border border-line p-3 sm:p-4">
      <template v-if="byKey[f.type]">
        <div class="grid grid-cols-[1fr_auto] items-start gap-2 sm:grid-cols-[220px_minmax(0,1fr)_auto]">
          <AppSelect
            :model-value="f.type" :options="typeOptions" searchable :disabled="f.is_locked"
            :aria-label="`Jenis kontak ${i + 1}`" class="col-span-2 sm:col-span-1" @update:model-value="(v) => changeType(f, v)"
          />

          <!-- isian sesuai jenis -->
          <div class="grid gap-1.5">
            <template v-if="byKey[f.type].input === 'handle'">
              <div class="relative">
                <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-muted">@</span>
                <AppInput
                  :model-value="f.value" :placeholder="byKey[f.type].placeholder" :disabled="f.is_locked" :invalid="!!err(i)" class="!pl-8"
                  autocapitalize="off" spellcheck="false" :aria-label="`Username ${byKey[f.type].label}`"
                  @update:model-value="(v) => (f.value = /^https?:/i.test(v) ? handleOf(byKey[f.type], v) : v.replace(/^@+/, ''))"
                />
              </div>
              <span v-if="preview(f)" class="truncate text-xs text-muted">{{ preview(f) }}</span>
            </template>
            <template v-else-if="byKey[f.type].input === 'bank'">
              <div class="grid gap-2 sm:grid-cols-2">
                <AppSelect v-model="f.meta.bank" :options="bankOptions" searchable placeholder="Pilih bank" :invalid="!!err(i, 'meta.bank')" aria-label="Bank" />
                <AppInput v-model="f.value" inputmode="numeric" placeholder="Nomor rekening" :invalid="!!err(i)" aria-label="Nomor rekening" />
              </div>
              <AppInput v-model="f.meta.holder" maxlength="120" placeholder="Atas nama" :invalid="!!err(i, 'meta.holder')" aria-label="Nama pemilik rekening" />
            </template>
            <template v-else-if="byKey[f.type].input === 'image'">
              <div class="flex items-center gap-3">
                <img v-if="f.image_url" :src="f.image_url" alt="QRIS" class="size-20 rounded-sm border border-line bg-bone-0 object-contain" />
                <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-md border border-line-strong px-4 text-sm font-semibold hover:bg-surface-2">
                  <ImageUp class="size-5" /> {{ uploading === f ? 'Mengunggah…' : f.image_url ? 'Ganti gambar' : 'Unggah QRIS' }}
                  <input class="sr-only" type="file" accept="image/png,image/jpeg,image/webp" :disabled="uploading === f" @change="(e) => upload(f, e)" />
                </label>
              </div>
            </template>
            <AppTextarea v-else-if="byKey[f.type].input === 'text'" v-model="f.value" rows="2" maxlength="300" :placeholder="byKey[f.type].placeholder" :disabled="f.is_locked" :aria-label="byKey[f.type].label" />
            <AppInput
              v-else v-model="f.value" :placeholder="byKey[f.type].placeholder" :disabled="f.is_locked" :invalid="!!err(i)"
              :inputmode="['phone', 'wallet'].includes(byKey[f.type].input) ? 'tel' : byKey[f.type].input === 'email' ? 'email' : 'url'"
              :aria-label="byKey[f.type].label"
            />
            <span v-for="k in ['value', 'meta.bank', 'meta.holder']" v-show="err(i, k)" :key="k" class="text-sm text-danger">{{ err(i, k) }}</span>
          </div>

          <div class="row-start-1 flex sm:row-start-auto">
            <AppButton variant="ghost" icon aria-label="Naikkan" :disabled="i === 0" @click="move(i, -1)"><ArrowUp class="size-4" /></AppButton>
            <AppButton variant="ghost" icon aria-label="Turunkan" :disabled="i === fields.length - 1" @click="move(i, 1)"><ArrowDown class="size-4" /></AppButton>
            <AppButton variant="ghost" icon aria-label="Hapus" :disabled="f.is_locked" @click="fields.splice(i, 1)"><X class="size-4" /></AppButton>
          </div>
        </div>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
          <AppInput v-model="f.label" maxlength="60" :placeholder="`Label (opsional), mis. ${byKey[f.type].label} kantor`" class="!min-h-10 max-w-xs" :aria-label="`Label kontak ${i + 1}`" />
          <AppCheckbox v-if="PRIMARY_TYPES.includes(f.type)" v-model="f.is_primary">Tombol cepat</AppCheckbox>
          <AppCheckbox v-if="byKey[f.type].vcard !== 'none'" v-model="f.in_vcard">Masuk vCard</AppCheckbox>
          <AppCheckbox v-model="f.is_visible">Tampil</AppCheckbox>
          <span v-if="f.is_locked" class="inline-flex items-center gap-1 text-sm text-muted"><Lock class="size-4" /> Dikunci perusahaan</span>
        </div>
      </template>
    </div>

    <p v-if="!fields.length" class="text-muted">Belum ada kontak. Tambahkan WhatsApp, Instagram, atau rekening agar orang mudah menghubungi & membayar Anda.</p>
    <div><AppButton variant="secondary" @click="picking = true"><Plus class="size-5" /> Tambah kontak / tautan</AppButton></div>
    <ContactTypePicker v-if="picking" :types="catalog.types" @pick="add" @close="picking = false" />
  </div>
</template>
