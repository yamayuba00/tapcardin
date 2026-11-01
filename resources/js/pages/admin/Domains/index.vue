<script setup>
// Domain kustom semua workspace — tambah, instruksi DNS (TXT verifikasi + CNAME), cek DNS, aktif/nonaktif.
import { computed } from 'vue'
import { Info } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppEmpty from '@/components/ui/AppEmpty/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import DomainAdd from '@/components/admin/DomainAdd/index.vue'
import DomainCard from '@/components/admin/DomainCard/index.vue'
import Pagination from '@/components/common/Pagination/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { usePagedList } from '@/composables/usePagedList'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const list = usePagedList(adminApi.domains, { q: '', status: '' }, { sort: '-created_at', perPage: 20 })
const rows = computed(() => list.rows.value ?? [])
const replace = (d) => { list.rows.value = list.rows.value.map((x) => (x.id === d.id ? d : x)) }
const drop = (id) => { list.rows.value = list.rows.value.filter((x) => x.id !== id) }
const setStatus = (v) => { list.filters.status = v; list.search() }
</script>

<template>
  <PageHeader eyebrow="Operasional" title="Domain kustom" />
  <p class="flex items-start gap-3 rounded-md border border-line-strong bg-surface-2 px-4 py-3 text-sm">
    <Info class="mt-0.5 size-5 flex-none text-info" />
    <span>Pelanggan memasang <b>TXT</b> untuk verifikasi kepemilikan dan <b>CNAME</b> ke domain aplikasi. Setelah DNS terverifikasi,
      dashboard workspace bisa dibuka di domain itu (server perlu menerima host apa pun &amp; menerbitkan sertifikat TLS otomatis).</span>
  </p>

  <AppPanel v-if="auth.can('tenants.manage')" title="Tambah domain"><DomainAdd @added="list.reload()" /></AppPanel>

  <div class="flex flex-wrap items-center gap-2">
    <form class="flex w-full gap-2 sm:w-auto" @submit.prevent="list.search">
      <AppInput v-model="list.filters.q" placeholder="Cari domain" aria-label="Cari domain" class="min-w-0 flex-1 sm:max-w-[240px]" />
      <AppButton type="submit" variant="secondary">Cari</AppButton>
    </form>
    <AppSegmented :model-value="list.filters.status" label="Status" class="sm:ml-auto"
      :options="[{ value: '', label: 'Semua' }, { value: 'pending', label: 'Menunggu' }, { value: 'active', label: 'Aktif' }, { value: 'disabled', label: 'Nonaktif' }]"
      @update:model-value="setStatus" />
  </div>

  <AppSkeleton v-if="list.rows.value === null" class="!h-40" />
  <AppEmpty v-else-if="!rows.length" text="Belum ada domain kustom. Tambahkan domain untuk workspace yang paketnya mencakup Domain kustom." />
  <div v-else class="grid gap-3">
    <DomainCard v-for="d in rows" :key="d.id" :domain="d" show-tenant @update="replace" @remove="drop" />
  </div>
  <Pagination v-if="list.meta.value?.last_page > 1" :meta="list.meta.value" @page="list.goto" />
</template>
