<script setup>
// Lembar QR + kode pendek per kartu (pratinjau cetak). SVG dari encoder QR server memakai currentColor.
import { useRoute } from 'vue-router'
import { Printer } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { useAsync } from '@/composables/useAsync'

const route = useRoute()
const { data } = useAsync(() => adminApi.batchPrint(route.params.id))
const print = () => window.print()
</script>

<template>
  <PageHeader class="no-print" title="Lembar QR" :back="`/batches/${route.params.id}`" :back-label="data?.code">
    <AppButton variant="secondary" @click="print"><Printer class="size-5" /> Cetak</AppButton>
  </PageHeader>
  <p class="no-print text-sm text-muted">QR berisi URL resolver permanen + <span class="font-mono">?src=qr</span>. Cetak minimal 15 × 15 mm dengan quiet zone 2 mm (DBD §8.1).</p>
  <AppSkeleton v-if="!data" class="!h-60" />
  <div v-else class="grid grid-cols-[repeat(auto-fill,minmax(200px,1fr))] gap-4">
    <div v-for="c in data.cards" :key="c.sequence" class="grid justify-items-center gap-2 rounded-md bg-bone-0 p-4 text-center text-ink-950">
      <div class="size-[140px] [&_svg]:size-full" v-html="c.svg"></div>
      <span class="font-mono">TC·{{ c.short_code }}</span>
      <span class="text-sm">#{{ c.sequence }}</span>
    </div>
  </div>
</template>
