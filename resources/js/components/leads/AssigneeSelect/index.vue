<script setup>
// Pilih penanggung jawab lead dari anggota yang boleh ditugaskan (Manager: hanya timnya) — dicari di server.
// Kosong = tanpa penanggung jawab. `current` = penanggung jawab saat ini agar labelnya langsung tampil.
import { computed } from 'vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import { leadsApi } from '@/api/workspace'

const model = defineModel({ type: String, default: '' })
const props = defineProps({ disabled: Boolean, current: { type: Object, default: null } })
const NONE = { value: '', label: 'Tanpa penanggung jawab' }

async function search(q) {
  const res = await leadsApi.assignees({ q: q || undefined, limit: 20 })
  const members = (res.data ?? []).map((m) => ({ value: m.user_id, label: m.name, description: m.email }))
  return q ? members : [NONE, ...members]
}
const selectedOption = computed(() => (props.current?.id ? { value: props.current.id, label: props.current.name } : null))
</script>

<template>
  <AppSelect v-model="model" :search="search" :selected-option="selectedOption" :disabled="disabled" placeholder="Pilih anggota" aria-label="Penanggung jawab" />
</template>
