<script setup>
// Akun staf — profil singkat & ganti password panel pemilik.
import { reactive, ref } from 'vue'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { useAction } from '@/composables/useAsync'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const { busy, run } = useAction()
const form = reactive({ current_password: '', password: '', password_confirmation: '' })
const errors = ref({})

async function save() {
  errors.value = {}
  try {
    await run(() => adminApi.changePassword(form), 'Password diperbarui.')
    Object.assign(form, { current_password: '', password: '', password_confirmation: '' })
  } catch (e) {
    errors.value = e.fields || {}
  }
}
</script>

<template>
  <PageHeader eyebrow="Panel pemilik" title="Akun" />
  <div class="grid gap-4 md:grid-cols-2">
    <AppPanel title="Profil">
      <dl class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
        <dt class="text-muted">Nama</dt><dd>{{ auth.me?.name }}</dd>
        <dt class="text-muted">Email</dt><dd>{{ auth.me?.email }}</dd>
        <dt class="text-muted">Peran</dt><dd><AppBadge tone="outline">{{ auth.me?.role }}</AppBadge></dd>
      </dl>
    </AppPanel>

    <AppPanel title="Ganti password">
      <form class="grid gap-4" @submit.prevent="save">
        <AppField label="Password saat ini" :error="errors.current_password?.[0]">
          <AppInput v-model="form.current_password" type="password" autocomplete="current-password" :invalid="!!errors.current_password" />
        </AppField>
        <AppField label="Password baru" hint="Minimal 8 karakter, berisi huruf dan angka." :error="errors.password?.[0]">
          <AppInput v-model="form.password" type="password" autocomplete="new-password" :invalid="!!errors.password" />
        </AppField>
        <AppField label="Ulangi password baru">
          <AppInput v-model="form.password_confirmation" type="password" autocomplete="new-password" />
        </AppField>
        <div><AppButton type="submit" :loading="busy">Simpan password</AppButton></div>
      </form>
    </AppPanel>
  </div>
</template>
