<script setup>
// AFD F-04 — generate → bundle vendor → encode → QC → kirim.
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { Boxes, CircleCheck, Cpu, Download, QrCode } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import CardsTable from '@/components/admin/CardsTable/index.vue'
import KpiCard from '@/components/common/KpiCard/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { useAction, useAsync } from '@/composables/useAsync'
import { usePagedList } from '@/composables/usePagedList'

const route = useRoute()
const id = route.params.id
const { data: b } = useAsync(() => adminApi.batch(id))
const { busy, run } = useAction()
const tracking = ref('')
const cards = usePagedList((p) => adminApi.batchCards(id, p), { q: '', progress: '' }, { url: false, sort: 'sequence', perPage: 50 })
const PROGRESS = [{ value: 'pending_encode', label: 'Belum di-encode' }, { value: 'pending_qc', label: 'Menunggu QC' }, { value: 'done', label: 'Lolos QC' }]

const toShip = computed(() => (b.value ? b.value.counts.qc - b.value.counts.shipped : 0))
const nextStatus = (v) => b.value?.next_statuses.some((s) => s.value === v)

async function act(fn, message) {
  const res = await run(fn, message).catch(() => null)
  if (res) {
    b.value = res.data
    cards.reload()
  }
}
</script>

<template>
  <AppSkeleton v-if="!b" class="!h-60" />
  <template v-else>
    <PageHeader :title="b.code" back="/batches" back-label="Produksi">
      <template #meta>
        <div class="flex flex-wrap gap-2">
          <AppBadge tone="info">{{ b.status_label }}</AppBadge>
          <AppBadge tone="outline">{{ b.chip_type }}</AppBadge>
          <AppBadge tone="outline">{{ b.quantity }} kartu</AppBadge>
          <AppBadge v-if="b.holder" tone="outline">{{ b.holder.email }}</AppBadge>
        </div>
      </template>
    </PageHeader>

    <div class="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-4">
      <KpiCard label="Token" :value="b.counts.tokens" />
      <KpiCard label="Di-encode" :value="b.counts.encoded" />
      <KpiCard label="Lolos QC" :value="b.counts.qc" />
      <KpiCard label="Dikirim" :value="b.counts.shipped" />
    </div>

    <AppPanel title="Langkah produksi">
      <div class="grid gap-4">
        <div class="flex flex-wrap gap-2">
          <AppButton v-if="b.status === 'draft'" :loading="busy" @click="act(() => adminApi.generateBatch(id), 'Token, kode pendek, dan kode aktivasi dibuat.')">1 · Buat token & kode aktivasi</AppButton>
          <template v-if="b.has_bundle">
            <AppButton variant="secondary" :href="adminApi.bundleUrl(id)"><Download class="size-5" /> Bundle vendor (CSV)</AppButton>
            <AppButton variant="secondary" :to="`/batches/${id}/print`"><QrCode class="size-5" /> Lembar QR</AppButton>
          </template>
          <AppButton v-if="nextStatus('sent_to_print')" variant="secondary" :loading="busy" @click="act(() => adminApi.batchStatus(id, 'sent_to_print'), 'Ditandai dikirim ke vendor')">Tandai dikirim ke vendor</AppButton>
          <AppButton v-if="nextStatus('printed')" variant="secondary" :loading="busy" @click="act(() => adminApi.batchStatus(id, 'printed'), 'Ditandai tercetak')">Tandai tercetak</AppButton>
          <AppButton v-if="b.counts.tokens && b.counts.encoded < b.counts.tokens" variant="secondary" :to="{ path: '/encode-station', query: { batch: id } }"><Cpu class="size-5" /> Stasiun encode</AppButton>
          <AppButton v-if="b.counts.encoded && b.counts.qc < b.counts.encoded" variant="secondary" :to="{ path: '/qc', query: { batch: id } }"><CircleCheck class="size-5" /> QC</AppButton>
        </div>
        <form v-if="toShip > 0" class="flex flex-wrap gap-2" @submit.prevent="act(() => adminApi.shipBatch(id, tracking || null), `${toShip} kartu dikirim dan siap diaktivasi.`)">
          <AppInput v-model="tracking" placeholder="Nomor resi (opsional)" maxlength="60" class="max-w-xs" />
          <AppButton type="submit" :loading="busy"><Boxes class="size-5" /> Kirim {{ toShip }} kartu lolos QC</AppButton>
        </form>
        <p v-if="toShip > 0" class="text-sm text-muted">Setelah dikirim, kartu berstatus "Belum diaktifkan" dan bisa diaktivasi pemiliknya.</p>
        <div v-if="nextStatus('cancelled')"><AppButton variant="ghost" :loading="busy" @click="act(() => adminApi.batchStatus(id, 'cancelled'), 'Batch dibatalkan')">Batalkan batch</AppButton></div>
      </div>
    </AppPanel>

    <template v-if="b.counts.tokens">
      <form class="flex flex-wrap gap-2" @submit.prevent="cards.search">
        <AppInput v-model="cards.filters.q" type="search" placeholder="Token atau kode pendek" aria-label="Cari kartu batch" class="max-w-xs" />
        <AppSelect v-model="cards.filters.progress" placeholder="Semua tahap" :options="PROGRESS" class="!w-auto" aria-label="Tahap" />
        <AppButton type="submit" variant="secondary">Cari</AppButton>
      </form>
      <CardsTable :list="cards" :show="['sequence', 'progress']" />
    </template>
  </template>
</template>
