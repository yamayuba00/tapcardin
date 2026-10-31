<script setup>
// A1 — tambah kartu dari dalam aplikasi tanpa tap/scan: kode kartu (tercetak dekat QR) + kode aktivasi (kemasan).
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { CircleAlert } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import { cardsApi } from '@/api/workspace'

const emit = defineEmits(['cancel'])
const router = useRouter()

const fmt = (v) => {
  const s = String(v).toUpperCase().replace(/^TC[·.\s-]*/, '').replace(/[^0-9A-Z]/g, '').slice(0, 8)
  return s.length > 4 ? `${s.slice(0, 4)}-${s.slice(4)}` : s
}
const shortRaw = ref('')
const codeRaw = ref('')
const shortCode = computed({ get: () => shortRaw.value, set: (v) => (shortRaw.value = fmt(v)) })
const code = computed({ get: () => codeRaw.value, set: (v) => (codeRaw.value = fmt(v)) })
const busy = ref(false)
const error = ref(null)

async function submit() {
  error.value = null
  busy.value = true
  try {
    const { data } = await cardsApi.claim(shortRaw.value, codeRaw.value || null)
    router.push(data.next)
  } catch (e) {
    error.value = e
    busy.value = false
  }
}
</script>

<template>
  <form class="grid gap-4" novalidate @submit.prevent="submit">
    <p class="text-sm text-muted">Pakai cara ini jika Anda sedang di laptop atau chip kartu tidak terbaca.</p>
    <AppField label="Kode kartu" hint="8 karakter tercetak kecil di dekat QR, mis. TC·7K2M-9QXP.">
      <AppInput v-model="shortCode" code maxlength="9" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXX-XXXX" autofocus />
    </AppField>
    <AppField label="Kode aktivasi" hint="Dari kartu kecil di kemasan. Kosongkan jika Anda pembeli kartu ini.">
      <AppInput v-model="code" code maxlength="9" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXX-XXXX" />
    </AppField>
    <p v-if="error" class="flex items-center gap-1 text-sm text-danger" role="alert"><CircleAlert class="size-4" /> {{ error.message }}</p>
    <div class="flex gap-2">
      <AppButton type="submit" :loading="busy" :disabled="shortRaw.replace('-', '').length !== 8">Aktifkan kartu</AppButton>
      <AppButton variant="secondary" @click="emit('cancel')">Batal</AppButton>
    </div>
  </form>
</template>
