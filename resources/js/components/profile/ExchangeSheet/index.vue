<script setup>
// P-04 / AFD F-08 — Tukar Kontak: nama*, WhatsApp atau email*, persetujuan*. Honeypot `website` + waktu render `t`.
import { reactive, ref } from 'vue'
import { CircleCheck, Download } from 'lucide-vue-next'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppTextarea from '@/components/ui/AppTextarea/index.vue'
import AppCheckbox from '@/components/ui/AppCheckbox/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import { publicApi } from '@/api/public'

const props = defineProps({ profile: { type: Object, required: true }, preview: Boolean })
const emit = defineEmits(['close'])

const form = reactive({ name: '', whatsapp: '', email: '', company: '', note: '', consent: false, website: '' })
const error = ref('')
const busy = ref(false)
const done = ref(false)
const firstName = props.profile.display_name.split(' ')[0] || props.profile.display_name

async function submit() {
  error.value = ''
  if (!form.name.trim()) return (error.value = 'Isi nama Anda.')
  if (!form.whatsapp && !form.email) return (error.value = 'Isi WhatsApp atau email.')
  if (!form.consent) return (error.value = 'Centang persetujuan untuk melanjutkan.')
  if (props.preview) return (done.value = true)
  busy.value = true
  try {
    await publicApi.connect(props.profile.exchange.url, { ...form, email: form.email || null, t: props.profile.exchange.t })
    done.value = true
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AppModal sheet :title="done ? '' : `Tukar kontak dengan ${firstName}`" @close="emit('close')">
    <div v-if="done" class="grid justify-items-center gap-4 py-4 text-center">
      <CircleCheck class="size-14 text-ok" aria-hidden="true" />
      <h2>Terkirim!</h2>
      <p class="text-muted">{{ profile.display_name }} akan menghubungi Anda.</p>
      <AppButton block :href="profile.vcard_url || undefined"><Download class="size-5" /> Simpan Kontak</AppButton>
    </div>
    <form v-else class="grid gap-4" novalidate @submit.prevent="submit">
      <p class="text-sm text-muted">Kontak Anda hanya dikirim ke {{ profile.display_name }}.</p>
      <div class="absolute -left-[9999px] size-px overflow-hidden" aria-hidden="true">
        <label>Website <input v-model="form.website" type="text" tabindex="-1" autocomplete="off" /></label>
      </div>
      <AppField label="Nama *"><AppInput v-model="form.name" maxlength="120" autocomplete="name" /></AppField>
      <AppField label="WhatsApp"><AppInput v-model="form.whatsapp" inputmode="tel" autocomplete="tel" placeholder="08…" /></AppField>
      <AppField label="Email"><AppInput v-model="form.email" type="email" autocomplete="email" /></AppField>
      <AppField label="Perusahaan"><AppInput v-model="form.company" maxlength="120" autocomplete="organization" /></AppField>
      <AppField label="Catatan"><AppTextarea v-model="form.note" maxlength="500" rows="2" /></AppField>
      <AppCheckbox v-model="form.consent">Saya setuju {{ profile.display_name }} menyimpan dan menghubungi saya.</AppCheckbox>
      <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>
      <AppButton type="submit" size="lg" block :loading="busy">Kirim kontak saya</AppButton>
    </form>
  </AppModal>
</template>
