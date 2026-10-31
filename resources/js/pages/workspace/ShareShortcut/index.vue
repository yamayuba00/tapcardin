<script setup>
// `/share` — tujuan shortcut PWA "Bagikan kartu": langsung ke halaman Bagikan kartu utama
// (kartu digital aktif, atau kartu aktif pertama). Tanpa kartu aktif → daftar kartu.
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import { cardsApi } from '@/api/workspace'

const router = useRouter()
onMounted(async () => {
  const cards = await cardsApi.list().then((r) => r.data).catch(() => [])
  const card = cards.find((c) => c.status === 'active' && c.form === 'digital') ?? cards.find((c) => c.status === 'active')
  router.replace(card ? `/cards/${card.token}/share` : '/cards')
})
</script>

<template>
  <div class="mx-auto grid w-full max-w-[440px] gap-4 py-4" aria-busy="true">
    <AppSkeleton class="!h-8 w-1/2" />
    <AppSkeleton class="!aspect-square !h-auto w-full" />
  </div>
</template>
