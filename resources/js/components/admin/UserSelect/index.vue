<script setup>
// Pilih akun yang sudah terdaftar (nama + email, bisa dicari). Memuat 100 akun pertama urut nama.
import { computed, onMounted, ref } from 'vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import { adminApi } from '@/api/admin'

const model = defineModel({ type: String, default: '' })
const props = defineProps({ by: { type: String, default: 'id' }, exclude: { type: String, default: '' }, invalid: Boolean })
const users = ref([])

onMounted(async () => {
  const res = await adminApi.users({ per_page: 100, sort: 'name' })
  users.value = (res.data ?? []).filter((u) => u.status === 'active')
})

const options = computed(() => users.value
  .filter((u) => u.id !== props.exclude)
  .map((u) => ({ value: u[props.by], label: u.name, description: u.email })))
</script>

<template>
  <AppSelect v-model="model" :options="options" searchable placeholder="Pilih akun terdaftar" :invalid="invalid" />
</template>
