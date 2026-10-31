<script setup>
// PL-07 — cari kartu via token / kode pendek / email pemegang.
import { Search } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import CardsTable from '@/components/admin/CardsTable/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { usePagedList } from '@/composables/usePagedList'
import { CARD_STATUS } from '@/lib/format'

const list = usePagedList(adminApi.cards, { q: '', status: '', replacement: '' }, { sort: '-updated_at', perPage: 50 })
const { filters, search } = list
const STATUSES = Object.entries(CARD_STATUS).map(([value, s]) => ({ value, label: s.label }))
</script>

<template>
  <PageHeader title="Kartu" />
  <form class="flex flex-wrap gap-2" @submit.prevent="search">
    <AppInput v-model="filters.q" type="search" placeholder="Token, kode pendek, atau email pemegang" aria-label="Cari kartu" class="max-w-xs" autofocus />
    <AppSelect v-model="filters.status" placeholder="Semua status" :options="STATUSES" class="!w-auto" aria-label="Status" />
    <AppSelect v-model="filters.replacement" placeholder="Semua kartu" :options="[{ value: '1', label: 'Minta pengganti' }]" class="!w-auto" aria-label="Permintaan pengganti" />
    <AppButton type="submit" variant="secondary"><Search class="size-5" /> Cari</AppButton>
  </form>
  <CardsTable :list="list" />
</template>
