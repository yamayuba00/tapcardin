<script setup>
// Masuk ke workspace perusahaan dengan email + kata sandi anggota (hanya di host perusahaan).
import { reactive, ref } from 'vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import { authApi } from '@/api/auth'

const emit = defineEmits(['success'])
const form = reactive({ email: '', password: '' })
const busy = ref(false)
const error = ref('')

async function submit() {
  error.value = ''
  if (!form.email || !form.password) return (error.value = 'Isi email dan kata sandi.')
  busy.value = true
  try {
    await authApi.passwordLogin(form.email, form.password)
    emit('success')
  } catch (e) {
    error.value = e.message
    busy.value = false
  }
}
</script>

<template>
  <form class="grid gap-4" novalidate @submit.prevent="submit">
    <p v-if="error" class="rounded-md border border-line-strong bg-surface-2 px-3 py-2 text-sm text-danger" role="alert">{{ error }}</p>
    <AppField label="Email perusahaan">
      <AppInput v-model.trim="form.email" type="email" autocomplete="username" placeholder="nama@perusahaan.com" />
    </AppField>
    <AppField label="Kata sandi">
      <AppInput v-model="form.password" type="password" autocomplete="current-password" :invalid="!!error" />
    </AppField>
    <AppButton type="submit" block :loading="busy">Masuk dengan email</AppButton>
  </form>
</template>
