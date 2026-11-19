<script setup>
// Halaman galat HTTP yang dirender server lewat shell SPA (config.error): 403, 404, 419, 429, 500, 503.
import { computed } from 'vue'
import { Clock, Lock, SearchX, ServerCrash, TimerReset, Wrench } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import CenterMessage from '@/components/common/CenterMessage/index.vue'
import { config } from '@/config'

const props = defineProps({ status: { type: Number, required: true } })

const COPY = {
  403: { icon: Lock, tone: 'warn', title: 'Akses ditolak', text: 'Anda tidak punya izin untuk membuka halaman ini.' },
  404: { icon: SearchX, tone: 'muted', title: 'Halaman tidak ditemukan', text: 'Tautan ini tidak ada atau sudah dipindahkan.' },
  419: { icon: TimerReset, tone: 'warn', title: 'Sesi sudah habis', text: 'Muat ulang halaman lalu coba lagi.' },
  429: { icon: Clock, tone: 'warn', title: 'Terlalu banyak akses sekaligus', text: 'Banyak orang membuka halaman ini dari jaringan yang sama. Tunggu sekitar satu menit, lalu coba lagi.' },
  500: { icon: ServerCrash, tone: 'danger', title: 'Ada yang salah di sistem kami', text: 'Kami sudah menerima laporannya. Coba lagi beberapa saat lagi.' },
  503: { icon: Wrench, tone: 'info', title: 'Sedang pemeliharaan', text: 'TapCard sedang diperbarui sebentar. Silakan coba lagi dalam beberapa menit.' },
}
const c = computed(() => COPY[props.status] ?? COPY[500])
const retry = computed(() => [419, 429, 500, 503].includes(props.status))
const reload = () => window.location.reload()
</script>

<template>
  <CenterMessage
    :title="c.title" :text="c.text" :icon="c.icon" :tone="c.tone" :badge="String(status)"
    :logo="config.tenant?.logo ?? undefined" :logo-alt="config.tenant?.name"
  >
    <AppButton v-if="retry" @click="reload">Coba lagi</AppButton>
    <AppButton v-else variant="secondary" href="/">Kembali ke beranda</AppButton>
  </CenterMessage>
</template>
