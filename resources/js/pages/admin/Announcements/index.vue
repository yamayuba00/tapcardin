<script setup>
// Pengumuman — notifikasi in-app ke semua pengguna / workspace personal / perusahaan / paket tertentu.
import { computed, reactive, ref } from 'vue'
import { Send } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppTextarea from '@/components/ui/AppTextarea/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { useAction, useAsync } from '@/composables/useAsync'
import { usePagedList } from '@/composables/usePagedList'
import { dateTime, num } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const list = usePagedList(adminApi.announcements, {}, { url: false, sort: '-created_at', perPage: 20 })
const COLUMNS = [{ key: 'title', label: 'Pengumuman' }, { key: 'audience', label: 'Penerima' }, { key: 'recipients', label: 'Terkirim', align: 'right' }, { key: 'created_at', label: 'Dibuat', sortable: true }]
const AUDIENCE = { all: 'Semua pengguna', personal: 'Workspace personal', company: 'Workspace perusahaan', plan: 'Paket tertentu' }
const { data: plans } = useAsync(() => (auth.can('plans.manage') ? adminApi.plans() : Promise.resolve({ data: [] })))
const planOptions = computed(() => (plans.value ?? []).map((p) => ({ value: p.key, label: p.name })))

const { busy, run } = useAction()
const form = reactive({ title: '', body: '', audience: 'all', plan: '' })
const errors = ref({})
const confirming = ref(false)
async function send() {
  errors.value = {}
  try {
    await run(() => adminApi.sendAnnouncement({ ...form, plan: form.audience === 'plan' ? form.plan : null }), 'Pengumuman dikirim ke antrean notifikasi.')
    Object.assign(form, { title: '', body: '', audience: 'all', plan: '' })
    list.reload()
  } catch (e) { errors.value = e.fields ?? {} }
  confirming.value = false
}
</script>

<template>
  <PageHeader eyebrow="Bisnis" title="Pengumuman" />
  <AppPanel title="Pengumuman baru">
    <form class="grid gap-4" @submit.prevent="confirming = true">
      <AppField label="Judul" :error="errors.title?.[0]"><AppInput v-model="form.title" maxlength="120" required /></AppField>
      <AppField label="Isi" :hint="`${form.body.length}/500`" :error="errors.body?.[0]"><AppTextarea v-model="form.body" maxlength="500" rows="3" required /></AppField>
      <div class="grid gap-4 sm:grid-cols-2">
        <AppField label="Penerima"><AppSelect v-model="form.audience" :options="Object.entries(AUDIENCE).map(([value, label]) => ({ value, label }))" /></AppField>
        <AppField v-if="form.audience === 'plan'" label="Paket" :error="errors.plan?.[0]"><AppSelect v-model="form.plan" :options="planOptions" placeholder="Pilih paket" /></AppField>
      </div>
      <div><AppButton type="submit"><Send class="size-5" /> Kirim pengumuman</AppButton></div>
    </form>
  </AppPanel>

  <DataTable dense :columns="COLUMNS" :rows="list.rows.value" :meta="list.meta.value" :sort="list.sort.value" :loading="list.loading.value"
    empty-text="Belum ada pengumuman." @sort="list.toggleSort" @page="list.goto" @per-page="list.setPerPage">
    <template #cell-title="{ row: a }"><span class="font-medium">{{ a.title }}</span><span class="block max-w-xl text-sm text-muted">{{ a.body }}</span></template>
    <template #cell-audience="{ row: a }">{{ AUDIENCE[a.audience] }}<span v-if="a.plan"> · {{ a.plan }}</span></template>
    <template #cell-recipients="{ row: a }">
      <span v-if="a.sent_at" class="font-mono">{{ num(a.recipients) }}</span><AppBadge v-else tone="info">Dalam antrean</AppBadge>
    </template>
    <template #cell-created_at="{ row: a }"><span class="whitespace-nowrap">{{ dateTime(a.created_at) }}</span></template>
  </DataTable>

  <AppModal v-if="confirming" size="sm" title="Kirim pengumuman?" :description="`“${form.title}” akan muncul di notifikasi ${AUDIENCE[form.audience].toLowerCase()}. Tidak bisa ditarik kembali.`" @close="confirming = false">
    <template #footer>
      <AppButton variant="secondary" @click="confirming = false">Batal</AppButton>
      <AppButton :loading="busy" @click="send">Kirim</AppButton>
    </template>
  </AppModal>
</template>
