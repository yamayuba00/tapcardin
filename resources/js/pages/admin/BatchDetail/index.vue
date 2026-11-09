<script setup>
// AFD F-04 — generate → bundle vendor → encode → QC → kirim.
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { Boxes, CircleCheck, Cpu, Download, ExternalLink, QrCode } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppMeter from '@/components/ui/AppMeter/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import CardsTable from '@/components/admin/CardsTable/index.vue'
import KpiCard from '@/components/common/KpiCard/index.vue'
import FlipCard from '@/components/studio/FlipCard/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { useAction, useAsync } from '@/composables/useAsync'
import { useAuthStore } from '@/stores/auth'
import { usePagedList } from '@/composables/usePagedList'

const route = useRoute()
const auth = useAuthStore()
const id = route.params.id
const { data: b } = useAsync(() => adminApi.batch(id))
const { busy, run } = useAction()
const tracking = ref('')
const cards = usePagedList((p) => adminApi.batchCards(id, p), { q: '', progress: '' }, { url: false, sort: 'sequence', perPage: 50 })
const PROGRESS = [{ value: 'pending_encode', label: 'Belum di-encode' }, { value: 'pending_qc', label: 'Menunggu QC' }, { value: 'done', label: 'Lolos QC' }]

const toShip = computed(() => (b.value ? b.value.counts.qc - b.value.counts.shipped : 0))
const nextStatus = (v) => b.value?.next_statuses.some((s) => s.value === v)

// pembuatan token berjalan di latar: muat ulang tiap 3 detik sampai selesai / gagal
let poll = null
watch(() => b.value?.generation?.running, (running) => {
  clearInterval(poll)
  if (running) {
    poll = setInterval(async () => {
      b.value = (await adminApi.batch(id)).data
      if (!b.value.generation?.running) cards.reload()
    }, 3000)
  }
})
onBeforeUnmount(() => clearInterval(poll))

// desain stok jual langsung: pratinjau + ganti (daftar & data contoh dimuat saat dibutuhkan)
const studio = ref(null)
const changing = ref(false)
const newDesign = ref('')
async function openDesigns() {
  studio.value ??= (await adminApi.batchDesigns()).data
  newDesign.value = b.value.design?.id ?? ''
  changing.value = true
}
const previewData = computed(() => studio.value ?? { vars: {}, qr: null })
watch(() => b.value?.design, (d) => { if (d && !studio.value) adminApi.batchDesigns().then((r) => (studio.value = r.data)) }, { immediate: true })

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
          <AppBadge tone="ok">{{ b.channel_label }}</AppBadge>
          <AppBadge v-if="b.tenant" tone="outline">{{ b.tenant }}</AppBadge>
          <AppBadge tone="outline">{{ b.quantity }} kartu</AppBadge>
          <RouterLink v-if="b.order" :to="`/orders/${b.order.id}`" class="text-sm">Pesanan {{ b.order.number }}</RouterLink>
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

    <AppPanel v-if="b.channel === 'direct'" title="Desain kartu">
      <div class="grid gap-4 lg:grid-cols-[minmax(0,32rem)_minmax(0,1fr)] lg:items-start">
        <div v-if="b.design && studio" class="grid grid-cols-2 gap-3">
          <figure v-for="side in ['front', 'back']" :key="side" class="grid gap-1">
            <FlipCard :front="b.design.front" :back="b.design.back" :flipped="side === 'back'" :vars="previewData.vars" :kit="b.design.kit" :qr="previewData.qr" />
            <figcaption class="text-center text-xs text-muted">{{ side === 'front' ? 'Depan' : 'Belakang' }}</figcaption>
          </figure>
        </div>
        <p v-else-if="!b.design" class="text-sm text-muted">Batch ini belum punya desain.</p>
        <div class="grid content-start gap-3">
          <p v-if="b.design" class="font-semibold">{{ b.design.name }}</p>
          <p v-if="b.design?.per_card?.length" class="rounded-md border border-warn p-3 text-sm text-warn" role="alert">
          Desain ini memuat {{ b.design.per_card.join(', ') }} yang berbeda di tiap kartu. Stok jual langsung dicetak sama semua — hapus elemen itu di Design Studio (cukup logo, tagline, & ikon NFC).
        </p>
          <div class="flex flex-wrap gap-2">
            <AppButton v-if="b.design" variant="secondary" :href="b.design.studio_url" target="_blank" rel="noopener"><ExternalLink class="size-5" /> Ubah di Design Studio</AppButton>
            <AppButton variant="ghost" @click="openDesigns">{{ b.design ? 'Ganti desain' : 'Pilih desain' }}</AppButton>
          </div>
          <form v-if="changing" class="flex flex-wrap gap-2" @submit.prevent="act(() => adminApi.setBatchDesign(id, newDesign || null), 'Desain batch diperbarui').then(() => (changing = false))">
            <AppSelect v-model="newDesign" :options="studio.designs.map((d) => ({ value: d.id, label: d.name }))" placeholder="Pilih desain" class="min-w-56" />
            <AppButton type="submit" :loading="busy">Simpan</AppButton>
          </form>
          <p class="text-sm text-muted">Perubahan di Design Studio langsung berlaku untuk batch ini. Desain hanya menentukan tampilan cetak — chip & aktivasi tidak terpengaruh.</p>
        </div>
      </div>
    </AppPanel>

    <AppPanel title="Langkah produksi">
      <div class="grid gap-4">
        <div class="flex flex-wrap gap-2">
          <AppButton v-if="b.status === 'draft' && !b.generation" :loading="busy" @click="act(() => adminApi.generateBatch(id), 'Token sedang dibuat di latar — halaman ini diperbarui otomatis.')">1 · Buat token & kode aktivasi</AppButton>
          <AppButton v-else-if="b.status === 'draft' && !b.generation.running" :loading="busy" @click="act(() => adminApi.generateBatch(id), 'Dilanjutkan dari kartu terakhir.')">Lanjutkan pembuatan token</AppButton>
          <template v-if="b.has_bundle && !b.uniform">
            <AppButton variant="secondary" :href="adminApi.bundleUrl(id)" title="Hanya untuk vendor cetak — tidak diperlukan untuk encode, QC, atau aktivasi"><Download class="size-5" /> Bundle vendor cetak (CSV, opsional)</AppButton>
            <AppButton variant="secondary" :to="`/batches/${id}/print`"><QrCode class="size-5" /> Lembar QR</AppButton>
          </template>
          <AppButton v-if="nextStatus('sent_to_print')" variant="secondary" :loading="busy" @click="act(() => adminApi.batchStatus(id, 'sent_to_print'), 'Ditandai dikirim ke vendor')">Tandai dikirim ke vendor</AppButton>
          <AppButton v-if="nextStatus('printed')" variant="secondary" :loading="busy" @click="act(() => adminApi.batchStatus(id, 'printed'), 'Ditandai tercetak')">Tandai tercetak</AppButton>
          <AppButton v-if="b.counts.tokens && b.counts.encoded < b.counts.tokens" variant="secondary" :to="{ path: '/encode-station', query: { batch: id } }"><Cpu class="size-5" /> Stasiun encode</AppButton>
          <AppButton v-if="b.counts.encoded && b.counts.qc < b.counts.encoded && !b.uniform" variant="secondary" :to="{ path: '/qc', query: { batch: id } }"><CircleCheck class="size-5" /> QC</AppButton>
        </div>
        <div v-if="b.status === 'draft' && b.generation" class="grid gap-2 rounded-md border border-line p-4" role="status">
          <AppMeter :label="b.generation.running ? 'Membuat token di latar…' : 'Pembuatan token berhenti'" :value="b.counts.tokens" :max="b.quantity">{{ b.counts.tokens }} / {{ b.quantity }}</AppMeter>
          <p v-if="b.generation.error" class="text-sm text-danger">{{ b.generation.error }}</p>
          <p v-else-if="b.generation.stalled" class="text-sm text-warn">Tidak ada kemajuan selama 15 menit — periksa worker antrean (<span class="font-mono">supervisorctl status</span>), lalu klik Lanjutkan.</p>
          <p v-else class="text-sm text-muted">Dibuat per 50 kartu. Halaman boleh ditutup; prosesnya tetap berjalan.</p>
        </div>
        <form v-if="toShip > 0" class="flex flex-wrap gap-2" @submit.prevent="act(() => adminApi.shipBatch(id, tracking || null), `${toShip} kartu dikirim dan siap diaktivasi.`)">
          <AppInput v-model="tracking" placeholder="Nomor resi (opsional)" maxlength="60" class="max-w-xs" />
          <AppButton type="submit" :loading="busy"><Boxes class="size-5" /> Kirim {{ toShip }} kartu lolos QC</AppButton>
        </form>
        <p v-if="toShip > 0" class="text-sm text-muted">
          Jual langsung? Kartu lolos QC bisa langsung diaktivasi untuk pelanggan dari tabel di bawah (tombol <b class="text-fg">Aktivasi</b>, filter "Lolos QC") — tanpa Kirim & tanpa kode aktivasi.
          Kirim hanya untuk kartu yang diserahkan ke pemiliknya agar mereka mengaktivasi sendiri.
        </p>
        <div v-if="nextStatus('cancelled')"><AppButton variant="ghost" :loading="busy" @click="act(() => adminApi.batchStatus(id, 'cancelled'), 'Batch dibatalkan')">Batalkan batch</AppButton></div>
      </div>
    </AppPanel>

    <template v-if="b.counts.tokens">
      <form class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap" @submit.prevent="cards.search">
        <AppInput v-model="cards.filters.q" type="search" placeholder="Token atau kode pendek" aria-label="Cari kartu batch" class="col-span-2 sm:max-w-xs" />
        <AppSelect v-model="cards.filters.progress" placeholder="Semua tahap" :options="PROGRESS" class="sm:!w-auto" aria-label="Tahap" />
        <AppButton type="submit" variant="secondary" class="col-span-2 sm:col-auto">Cari</AppButton>
      </form>
      <CardsTable :list="cards" :show="auth.can('cards.manage') ? ['sequence', 'progress', 'activate'] : ['sequence', 'progress']" />
    </template>
  </template>
</template>
