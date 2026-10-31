<script setup>
// P-07 — QR dinamis sudah dipakai / lewat 60 detik (server membalas 410 dengan shell ini).
import { TimerOff } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import CenterMessage from '@/components/common/CenterMessage/index.vue'
</script>

<template>
  <CenterMessage title="QR sudah kedaluwarsa" :icon="TimerOff" tone="warn" badge="QR sekali pakai" promo text="QR ini hanya berlaku sekali dan selama 60 detik. Minta pemilik kartu menampilkan QR yang baru, lalu scan lagi.">
    <AppButton variant="secondary" to="/k">Masukkan kode kartu</AppButton>
  </CenterMessage>
</template>
