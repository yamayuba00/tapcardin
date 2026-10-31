<script setup>
// `/@{handle}` — tautan profil personal (WS-07) → kartu aktif terbaru pemiliknya.
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { UserX } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import CenterMessage from '@/components/common/CenterMessage/index.vue'
import { publicApi } from '@/api/public'

const route = useRoute()
const router = useRouter()
const missing = ref(false)

onMounted(async () => {
  try {
    const { data } = await publicApi.handle(String(route.params.handle).toLowerCase())
    router.replace({ name: 'card', params: { token: data.token }, query: { src: 'link' } })
  } catch {
    missing.value = true
  }
})
</script>

<template>
  <CenterMessage v-if="missing" title="Profil tidak ditemukan" :icon="UserX" badge="Tidak dikenal" promo text="Nama pengguna ini belum terhubung ke kartu aktif.">
    <AppButton variant="secondary" to="/k">Masukkan kode kartu</AppButton>
  </CenterMessage>
</template>
