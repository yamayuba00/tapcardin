<script setup>
// Login panel pemilik — email + password (hanya staf aktif). Sesi berakhir setiap 12 jam.
import { reactive, ref } from 'vue'
import { Eye, EyeOff } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import { authApi } from '@/api/auth'

const emit = defineEmits(['success'])
const form = reactive({ email: '', password: '' })
const show = ref(false)
const busy = ref(false)
const error = ref('')

async function submit() {
  error.value = ''
  if (!form.email || !form.password) return (error.value = 'Isi email dan password.')
  busy.value = true
  try {
    await authApi.adminLogin(form.email, form.password)
    emit('success')
  } catch (e) {
    error.value = e.message
    busy.value = false
  }
}

/** Dipakai tombol akun dummy (mode lokal). */
function fill(email, password) {
  form.email = email
  form.password = password
  return submit()
}
defineExpose({ fill })
</script>

<template>
  <form class="grid gap-4" novalidate @submit.prevent="submit">
    <AppField label="Email"><AppInput v-model.trim="form.email" type="email" autocomplete="username" placeholder="nama@tapcard.id" autofocus /></AppField>
    <AppField label="Password" :error="error">
      <div class="relative">
        <AppInput v-model="form.password" :type="show ? 'text' : 'password'" autocomplete="current-password" class="pr-12" :invalid="!!error" />
        <button type="button" class="cursor-pointer absolute inset-y-0 right-0 grid w-12 place-items-center text-muted" :aria-label="show ? 'Sembunyikan password' : 'Tampilkan password'" @click="show = !show">
          <component :is="show ? EyeOff : Eye" class="size-5" />
        </button>
      </div>
    </AppField>
    <AppButton type="submit" block :loading="busy">Masuk</AppButton>
    <RouterLink to="/forgot-password" class="inline-flex min-h-11 items-center justify-self-center text-sm font-semibold text-accent-text underline">Lupa password?</RouterLink>
  </form>
</template>
