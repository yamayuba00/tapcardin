<script setup>
// Area perusahaan: karyawan, kartu karyawan, rekap lead — tab disimpan di ?tab=.
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import CompanyCards from '@/components/company/CompanyCards/index.vue'
import LeadRecap from '@/components/company/LeadRecap/index.vue'
import MemberList from '@/components/company/MemberList/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
// hanya owner/admin perusahaan; anggota biasa kembali ke beranda
if (auth.workspace?.type === 'personal' || !auth.can('members.invite')) router.replace('/dashboard')
const tabs = computed(() => [
  { value: 'members', label: 'Karyawan' },
  ...(auth.can('cards.assign.all') ? [{ value: 'cards', label: 'Kartu karyawan' }] : []),
  ...(auth.can('analytics.view.all') ? [{ value: 'leads', label: 'Rekap lead' }] : []),
])
const tab = computed({
  get: () => (tabs.value.some((t) => t.value === route.query.tab) ? route.query.tab : 'members'),
  set: (v) => router.replace({ query: { tab: v === 'members' ? undefined : v } }),
})
</script>

<template>
  <PageHeader eyebrow="Perusahaan" :title="auth.workspace?.name ?? 'Karyawan'" />
  <AppSegmented v-model="tab" :options="tabs" label="Bagian" />
  <MemberList v-if="tab === 'members'" />
  <CompanyCards v-else-if="tab === 'cards'" />
  <LeadRecap v-else />
</template>
