<script setup>
// Email blast promo — daftar blast dengan status & progres kirim. Terpisah dari Pengumuman (notifikasi in-app).
import { onBeforeUnmount, watch } from 'vue'
import { Plus } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppMeter from '@/components/ui/AppMeter/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { usePagedList } from '@/composables/usePagedList'
import { dateTime, num } from '@/lib/format'
import { AUDIENCE, STATUS, progress } from '@/lib/markdownEdit'

const list = usePagedList(adminApi.emailBlasts, {}, { sort: '-created_at', perPage: 20 })
const COLUMNS = [
  { key: 'subject', label: 'Subjek' },
  { key: 'audience', label: 'Penerima' },
  { key: 'status', label: 'Status' },
  { key: 'progress', label: 'Progres' },
  { key: 'created_at', label: 'Dibuat', sortable: true },
]

// selama ada blast yang sedang dikirim, muat ulang daftar tiap 10 detik
let timer = null
watch(() => list.rows.value, (rows) => {
  clearTimeout(timer)
  if (rows?.some((b) => b.status === 'sending')) timer = setTimeout(() => list.reload(), 10000)
})
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <PageHeader eyebrow="Bisnis" title="Email blast">
    <AppButton to="/email-blasts/new"><Plus class="size-5" /> Blast baru</AppButton>
  </PageHeader>
  <p class="max-w-2xl text-sm text-muted">Email promo ke pengguna (bukan notifikasi in-app). Alamat yang berhenti berlangganan dan pengguna diblokir otomatis dilewati.</p>

  <DataTable dense :columns="COLUMNS" :rows="list.rows.value" :meta="list.meta.value" :sort="list.sort.value" :loading="list.loading.value"
    empty-text="Belum ada email blast." @sort="list.toggleSort" @page="list.goto" @per-page="list.setPerPage">
    <template #cell-subject="{ row: b }">
      <RouterLink :to="`/email-blasts/${b.id}`" class="font-medium">{{ b.subject }}</RouterLink>
      <span v-if="b.preheader" class="block max-w-md truncate text-sm text-muted">{{ b.preheader }}</span>
    </template>
    <template #cell-audience="{ row: b }">{{ AUDIENCE[b.audience] }}<span v-if="b.plan_name"> · {{ b.plan_name }}</span></template>
    <template #cell-status="{ row: b }">
      <AppBadge :tone="STATUS[b.status]?.tone">{{ STATUS[b.status]?.label ?? b.status }}</AppBadge>
      <span v-if="b.status === 'scheduled'" class="block whitespace-nowrap text-sm text-muted">{{ dateTime(b.scheduled_at) }}</span>
    </template>
    <template #cell-progress="{ row: b }">
      <div v-if="b.counts.total" class="min-w-44">
        <AppMeter :value="progress(b.counts)" :max="100" plain>
          <span class="text-xs">{{ num(b.counts.sent) }}/{{ num(b.counts.total) }}<template v-if="b.counts.failed"> · {{ num(b.counts.failed) }} gagal</template><template v-if="b.counts.skipped"> · {{ num(b.counts.skipped) }} lewat</template></span>
        </AppMeter>
      </div>
      <span v-else class="text-sm text-muted">—</span>
    </template>
    <template #cell-created_at="{ row: b }"><span class="whitespace-nowrap">{{ dateTime(b.created_at) }}</span></template>
  </DataTable>
</template>
