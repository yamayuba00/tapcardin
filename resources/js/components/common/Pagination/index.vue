<script setup>
import { ArrowLeft, ArrowRight } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'

const props = defineProps({ meta: { type: Object, default: null } })
const emit = defineEmits(['page'])
</script>

<template>
  <nav v-if="meta && meta.last_page > 1" class="flex items-center justify-end gap-2" aria-label="Halaman">
    <AppButton variant="secondary" :disabled="meta.page <= 1" @click="emit('page', meta.page - 1)"><ArrowLeft class="size-5" /> Sebelumnya</AppButton>
    <span class="text-sm text-muted">Halaman {{ meta.page }} dari {{ meta.last_page }}</span>
    <AppButton variant="secondary" :disabled="meta.page >= meta.last_page" @click="emit('page', meta.page + 1)">Berikutnya <ArrowRight class="size-5" /></AppButton>
  </nav>
</template>
