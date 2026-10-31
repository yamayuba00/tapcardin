<script setup>
// P-06 — input kode pendek manual (XXXX-XXXX) untuk ponsel tanpa NFC/kamera.
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { CreditCard } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import CenterMessage from '@/components/common/CenterMessage/index.vue'
import { publicApi } from '@/api/public'

const router = useRouter()
const code = ref('')
const error = ref('')
const busy = ref(false)

async function submit() {
  error.value = ''
  busy.value = true
  try {
    const { data } = await publicApi.shortCode(code.value)
    router.push({ name: 'card', params: { token: data.token }, query: { src: 'code' } })
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <CenterMessage title="Masukkan kode kartu" :icon="CreditCard" tone="info" promo>
    <p class="text-muted">Kode 8 karakter tercetak kecil di dekat QR, misalnya <span class="font-mono tracking-[.2em]">7K2M-9QXP</span>.</p>
    <form class="grid w-full gap-4" @submit.prevent="submit">
      <AppField label="Kode kartu" sr-only :error="error">
        <AppInput v-model="code" code maxlength="12" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXX-XXXX" required autofocus :invalid="!!error" />
      </AppField>
      <AppButton type="submit" size="lg" block :loading="busy">Buka profil</AppButton>
    </form>
  </CenterMessage>
</template>
