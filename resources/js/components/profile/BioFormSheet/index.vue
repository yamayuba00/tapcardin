<script setup>
// Formulir bio link (blok `form`) untuk pengunjung: nama + WhatsApp/email (sesuai pengaturan pemilik), lalu pertanyaan
// buatan pemilik, persetujuan, honeypot `website` + waktu render `t`. Server memvalidasi ulang (PublicFormController).
import { reactive, ref } from 'vue'
import { CircleCheck } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppCheckbox from '@/components/ui/AppCheckbox/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppTextarea from '@/components/ui/AppTextarea/index.vue'
import { publicApi } from '@/api/public'

const props = defineProps({
  block: { type: Object, required: true }, // { label, title, description, success, contact, fields, url, t }
  owner: { type: String, required: true }, // nama pemilik bio
  preview: Boolean,
})
const emit = defineEmits(['close'])

const form = reactive({ name: '', whatsapp: '', email: '', consent: false, website: '' })
const answers = reactive(Object.fromEntries(props.block.fields.map((f) => [f.id, f.type === 'multi' ? [] : ''])))
const errors = ref({})
const error = ref('')
const busy = ref(false)
const done = ref(null) // pesan terima kasih

const wantsWa = props.block.contact !== 'email'
const wantsMail = props.block.contact !== 'whatsapp'

function toggle(id, option, on) {
  const list = answers[id]
  answers[id] = on ? [...list, option] : list.filter((o) => o !== option)
}

function check() {
  const e = {}
  if (!form.name.trim()) e.name = 'Isi nama Anda.'
  if (props.block.contact === 'whatsapp' && !form.whatsapp.trim()) e.whatsapp = 'Isi nomor WhatsApp.'
  if (props.block.contact === 'email' && !form.email.trim()) e.email = 'Isi email.'
  if (props.block.contact === 'both' && !form.whatsapp.trim() && !form.email.trim()) e.whatsapp = 'Isi WhatsApp atau email.'
  for (const f of props.block.fields) {
    const v = answers[f.id]
    if (f.required && (Array.isArray(v) ? !v.length : !String(v).trim())) e[`answers.${f.id}`] = 'Wajib diisi.'
  }
  if (!form.consent) e.consent = 'Centang persetujuan untuk melanjutkan.'
  errors.value = e
  return !Object.keys(e).length
}

async function submit() {
  error.value = ''
  if (!check()) return (error.value = 'Periksa kembali isian yang ditandai.')
  if (props.preview) return (done.value = props.block.success)
  busy.value = true
  try {
    const res = await publicApi.connect(props.block.url, {
      ...form, whatsapp: form.whatsapp || null, email: form.email || null, answers: { ...answers }, t: props.block.t,
    })
    done.value = res.data.message || props.block.success
  } catch (e) {
    errors.value = Object.fromEntries(Object.entries(e.fields ?? {}).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v]))
    error.value = e.message
  } finally {
    busy.value = false
  }
}
const err = (k) => errors.value[k]
</script>

<template>
  <AppModal sheet :title="done ? '' : block.title" :description="done ? '' : block.description" @close="emit('close')">
    <div v-if="done" class="grid justify-items-center gap-4 py-4 text-center">
      <CircleCheck class="size-14 text-ok" aria-hidden="true" />
      <h2>Terkirim!</h2>
      <p class="whitespace-pre-line text-muted">{{ done }}</p>
      <AppButton variant="secondary" block @click="emit('close')">Tutup</AppButton>
    </div>
    <form v-else class="grid gap-4" novalidate @submit.prevent="submit">
      <div class="absolute -left-[9999px] size-px overflow-hidden" aria-hidden="true">
        <label>Website <input v-model="form.website" type="text" tabindex="-1" autocomplete="off" /></label>
      </div>
      <AppField label="Nama *" :error="err('name')"><AppInput v-model="form.name" maxlength="120" autocomplete="name" :invalid="!!err('name')" /></AppField>
      <AppField v-if="wantsWa" :label="block.contact === 'whatsapp' ? 'WhatsApp *' : 'WhatsApp'" :error="err('whatsapp')">
        <AppInput v-model="form.whatsapp" inputmode="tel" autocomplete="tel" placeholder="08…" :invalid="!!err('whatsapp')" />
      </AppField>
      <AppField v-if="wantsMail" :label="block.contact === 'email' ? 'Email *' : 'Email'" :error="err('email')">
        <AppInput v-model="form.email" type="email" autocomplete="email" :invalid="!!err('email')" />
      </AppField>
      <p v-if="block.contact === 'both'" class="-mt-2 text-xs text-muted">Isi salah satu: WhatsApp atau email.</p>

      <template v-for="f in block.fields" :key="f.id">
        <fieldset v-if="f.type === 'multi'" class="grid gap-2">
          <legend class="mb-2 text-sm font-medium text-muted">{{ f.label }}{{ f.required ? ' *' : '' }}</legend>
          <AppCheckbox v-for="o in f.options" :key="o" :model-value="answers[f.id].includes(o)" @update:model-value="(v) => toggle(f.id, o, v)">{{ o }}</AppCheckbox>
          <span v-if="err(`answers.${f.id}`)" class="text-sm text-danger">{{ err(`answers.${f.id}`) }}</span>
        </fieldset>
        <AppField v-else :label="`${f.label}${f.required ? ' *' : ''}`" :error="err(`answers.${f.id}`)">
          <AppTextarea v-if="f.type === 'textarea'" v-model="answers[f.id]" maxlength="2000" rows="3" :placeholder="f.placeholder" :invalid="!!err(`answers.${f.id}`)" />
          <AppSelect v-else-if="f.type === 'select'" v-model="answers[f.id]" :options="f.options.map((o) => ({ value: o, label: o }))" placeholder="Pilih…" :invalid="!!err(`answers.${f.id}`)" :aria-label="f.label" />
          <AppInput
            v-else v-model="answers[f.id]" :maxlength="f.type === 'text' ? 200 : 190" :placeholder="f.placeholder" :invalid="!!err(`answers.${f.id}`)"
            :type="{ email: 'email', date: 'date' }[f.type] ?? 'text'" :inputmode="{ phone: 'tel', number: 'decimal' }[f.type]"
          />
        </AppField>
      </template>

      <div class="grid gap-1">
        <AppCheckbox v-model="form.consent">Saya setuju {{ owner }} menyimpan data ini dan menghubungi saya.</AppCheckbox>
        <span v-if="err('consent')" class="text-sm text-danger">{{ err('consent') }}</span>
      </div>
      <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>
      <AppButton type="submit" size="lg" block :loading="busy">Kirim</AppButton>
    </form>
  </AppModal>
</template>
