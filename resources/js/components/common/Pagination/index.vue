<script setup>
import { ArrowLeft, ArrowRight } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'

const props = defineProps({ meta: { type: Object, default: null } })
const emit = defineEmits(['page'])
</script>

<template>
  <!-- Ponsel: tombol berisi ikon saja + "2 / 5" di tengah supaya muat satu baris; teks lengkap mulai sm. -->
  <nav v-if="meta && meta.last_page > 1" class="flex items-center justify-between gap-2 sm:justify-end" aria-label="Halaman">
    <AppButton variant="secondary" :disabled="meta.page <= 1" aria-label="Halaman sebelumnya" @click="emit('page', meta.page - 1)"><ArrowLeft class="size-5" /> <span class="hidden sm:inline">Sebelumnya</span></AppButton>
    <span class="text-sm text-muted"><span class="sm:hidden">{{ meta.page }} / {{ meta.last_page }}</span><span class="hidden sm:inline">Halaman {{ meta.page }} dari {{ meta.last_page }}</span></span>
    <AppButton variant="secondary" :disabled="meta.page >= meta.last_page" aria-label="Halaman berikutnya" @click="emit('page', meta.page + 1)"><span class="hidden sm:inline">Berikutnya</span> <ArrowRight class="size-5" /></AppButton>
  </nav>
</template>
