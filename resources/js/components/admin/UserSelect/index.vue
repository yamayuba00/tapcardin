<script setup>
// Pilih akun aktif yang sudah terdaftar (nama + email) — dicari di server, 20 hasil per ketikan.
import AppSelect from '@/components/ui/AppSelect/index.vue'
import { adminApi } from '@/api/admin'

const model = defineModel({ type: String, default: '' })
const props = defineProps({ by: { type: String, default: 'id' }, exclude: { type: String, default: '' }, invalid: Boolean })

async function search(q) {
  const res = await adminApi.users({ q: q || undefined, status: 'active', per_page: 20, sort: 'name' })
  return (res.data ?? []).filter((u) => u.id !== props.exclude).map((u) => ({ value: u[props.by], label: u.name, description: u.email }))
}
</script>

<template>
  <AppSelect v-model="model" :search="search" placeholder="Cari nama atau email akun" :invalid="invalid" />
</template>
