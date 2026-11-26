<script setup>
// Aktivasi undangan karyawan (host perusahaan): konfirmasi email yang diundang, lalu buat kata sandi.
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AuthShell from '@/components/auth/AuthShell/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import { authApi } from '@/api/auth'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const info = ref(null)
const loadError = ref('')
const form = reactive({ email: '', name: '', password: '', password_confirmation: '' })
const errors = ref({})
const busy = ref(false)

onMounted(async () => {
  try {
    info.value = (await authApi.inviteInfo(route.params.token)).data
  } catch (e) {
    loadError.value = e.message
  }
})

async function submit() {
  errors.value = {}
  busy.value = true
  try {
    await authApi.acceptInvite(route.params.token, { ...form })
    await auth.load(true)
    router.replace('/dashboard')
  } catch (e) {
    if (e.fields) {
      errors.value = e.fields
      busy.value = false
    } else {
      loadError.value = e.message // tautan tidak berlaku: pesan umum, form disembunyikan
    }
  }
}
</script>

<template>
  <AuthShell
    :title="info ? `Gabung ke ${info.workspace.name}` : 'Undangan karyawan'"
    subtitle="Konfirmasi email yang menerima undangan, lalu buat kata sandi untuk masuk."
  >
    <p v-if="loadError" class="rounded-md border border-line-strong bg-surface-2 px-3 py-3 text-sm text-danger" role="alert">{{ loadError }}</p>
    <form v-else-if="info" class="grid gap-4" novalidate @submit.prevent="submit">
      <p class="text-sm text-muted">Peran Anda: <strong>{{ info.role }}</strong>. Ketik email yang menerima undangan ini.</p>
      <AppField label="Email undangan" :error="errors.email?.[0]">
        <AppInput v-model.trim="form.email" type="email" autocomplete="email" required :invalid="!!errors.email" />
      </AppField>
      <AppField label="Nama (opsional)" :error="errors.name?.[0]">
        <AppInput v-model="form.name" autocomplete="name" maxlength="120" />
      </AppField>
      <AppField label="Kata sandi (minimal 8 karakter)" :error="errors.password?.[0]">
        <AppInput v-model="form.password" type="password" autocomplete="new-password" required :invalid="!!errors.password" />
      </AppField>
      <AppField label="Ulangi kata sandi" :error="errors.password_confirmation?.[0]">
        <AppInput v-model="form.password_confirmation" type="password" autocomplete="new-password" required :invalid="!!errors.password_confirmation" />
      </AppField>
      <AppButton type="submit" block :loading="busy">Bergabung</AppButton>
    </form>
    <p v-else class="text-sm text-muted">Memeriksa undangan…</p>
  </AuthShell>
</template>
