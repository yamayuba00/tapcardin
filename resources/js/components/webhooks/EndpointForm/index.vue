<script setup>
// Dialog tambah / ubah endpoint webhook: URL https, deskripsi, event yang dikirim (dikelompokkan), aktif.
import { computed, ref } from 'vue'
import { TriangleAlert } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppCheckbox from '@/components/ui/AppCheckbox/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppToggle from '@/components/ui/AppToggle/index.vue'
import { webhooksApi } from '@/api/workspace'
import { useAction } from '@/composables/useAsync'

const props = defineProps({ endpoint: { type: Object, default: null }, events: { type: Array, required: true } })
const emit = defineEmits(['saved', 'close'])
const { busy, run } = useAction()
const form = ref({
  url: props.endpoint?.url ?? '',
  description: props.endpoint?.description ?? '',
  events: [...(props.endpoint?.events ?? ['lead.created', 'contact.exchanged'])],
  is_active: props.endpoint?.is_active ?? true,
})
const errors = ref({})

const groups = computed(() => {
  const out = {}
  props.events.forEach((e) => (out[e.group] ??= []).push(e))
  return out
})
const has = (key) => form.value.events.includes(key)
function toggle(key, on) {
  form.value.events = on ? [...new Set([...form.value.events, key])] : form.value.events.filter((k) => k !== key)
}
const volumeOn = computed(() => props.events.some((e) => e.volume && has(e.key)))

async function save() {
  errors.value = {}
  try {
    const body = { ...form.value, description: form.value.description || null }
    const res = await run(() => (props.endpoint ? webhooksApi.update(props.endpoint.id, body) : webhooksApi.create(body)),
      props.endpoint ? 'Endpoint diperbarui.' : null)
    emit('saved', res.data)
  } catch (e) { errors.value = e.fields ?? {} }
}
</script>

<template>
  <AppModal :title="endpoint ? 'Ubah endpoint' : 'Tambah endpoint'" description="TapCard mengirim POST JSON bertanda tangan ke URL ini setiap event terjadi." @close="emit('close')">
    <form id="webhook-endpoint" class="grid gap-4" @submit.prevent="save">
      <AppField label="URL tujuan" :error="errors.url?.[0]" hint="Wajib https://. Alamat jaringan internal ditolak.">
        <AppInput v-model="form.url" type="url" required maxlength="500" placeholder="https://sistem-anda.com/webhooks/tapcard" :invalid="!!errors.url" />
      </AppField>
      <AppField label="Keterangan (opsional)" :error="errors.description?.[0]">
        <AppInput v-model="form.description" maxlength="120" placeholder="mis. ERP produksi, Zapier, n8n" />
      </AppField>
      <fieldset class="grid gap-3">
        <legend class="mb-1 text-sm font-medium">Event yang dikirim</legend>
        <p v-if="errors.events" class="text-sm text-danger" role="alert">{{ errors.events[0] }}</p>
        <div v-for="(list, group) in groups" :key="group" class="grid gap-1.5">
          <span class="label">{{ group }}</span>
          <AppCheckbox v-for="e in list" :key="e.key" :model-value="has(e.key)" @update:model-value="(v) => toggle(e.key, v)">
            <span class="font-mono text-sm">{{ e.key }}</span> <span class="text-sm text-muted">— {{ e.label }}</span>
          </AppCheckbox>
        </div>
        <p v-if="volumeOn" class="flex gap-2 rounded-md border border-line-strong bg-surface-2 px-3 py-2 text-sm">
          <TriangleAlert class="size-4 flex-none translate-y-0.5 text-warn" />
          <span><b>tap.recorded</b> dikirim untuk setiap tap & scan QR — bisa ribuan per hari. Pastikan server Anda sanggup.</span>
        </p>
      </fieldset>
      <AppToggle v-if="endpoint" v-model="form.is_active" label="Aktif" />
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="emit('close')">Batal</AppButton>
      <AppButton type="submit" form="webhook-endpoint" :loading="busy" :disabled="!form.events.length">Simpan</AppButton>
    </template>
  </AppModal>
</template>
