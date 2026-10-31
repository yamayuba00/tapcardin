<script setup>
import { SearchX } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import CenterMessage from '@/components/common/CenterMessage/index.vue'
</script>

<template>
  <CenterMessage title="Halaman tidak ditemukan" :icon="SearchX" badge="404" text="Tautan ini tidak ada atau sudah dipindahkan.">
    <AppButton variant="secondary" to="/">Kembali</AppButton>
  </CenterMessage>
</template>
