<script setup>
// Semua notifikasi dengan saring per jenis; tandai semua terbaca.
import { ref } from 'vue'
import { CheckCheck } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import NotificationList from '@/components/common/NotificationList/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { useNotificationStore } from '@/stores/notifications'

const store = useNotificationStore()
const filter = ref('')
const FILTERS = [{ value: '', label: 'Semua' }, { value: 'tap', label: 'Tap' }, { value: 'lead', label: 'Lead' }, { value: 'billing', label: 'Tagihan' }, { value: 'order', label: 'Pesanan' }]
</script>

<template>
  <PageHeader title="Notifikasi">
    <AppButton v-if="store.unread" variant="secondary" @click="store.markAllRead()"><CheckCheck class="size-5" /> Tandai semua terbaca</AppButton>
  </PageHeader>
  <AppSegmented v-model="filter" label="Jenis notifikasi" :options="FILTERS" />
  <AppPanel flush><div class="p-2"><NotificationList :filter="filter" /></div></AppPanel>
</template>
