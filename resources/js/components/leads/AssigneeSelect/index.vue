<script setup>
// Pilih penanggung jawab lead dari anggota yang boleh ditugaskan (Manager: hanya timnya). Kosong = tanpa penanggung jawab.
import { computed, onMounted, ref } from 'vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import { leadsApi } from '@/api/workspace'

const model = defineModel({ type: String, default: '' })
defineProps({ disabled: Boolean })
const members = ref([])

let cache = null
onMounted(async () => {
  cache ??= leadsApi.assignees().then((r) => r.data ?? []).catch(() => [])
  members.value = await cache
})
const options = computed(() => [
  { value: '', label: 'Tanpa penanggung jawab' },
  ...members.value.map((m) => ({ value: m.user_id, label: m.name, description: m.email })),
])
</script>

<template>
  <AppSelect v-model="model" :options="options" searchable :disabled="disabled" placeholder="Pilih anggota" aria-label="Penanggung jawab" />
</template>
