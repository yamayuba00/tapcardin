<script setup>
// U-12 — desain kartu, lanyard, & merchandise milik pengguna. Kartu baru dari kartu kosong / 8 template / template saya;
// lanyard baru berdiri sendiri atau berpasangan dengan kartu (brand kit bersama, pratinjau gabungan);
// merchandise (jaket, kaos, tote bag, mug, topi) sudah bisa didesain — pemesanan cetaknya segera hadir.
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Link2, Pencil, Plus, ShoppingCart, Trash2 } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppEmpty from '@/components/ui/AppEmpty/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import FlipCard from '@/components/studio/FlipCard/index.vue'
import LanyardStrip from '@/components/studio/LanyardStrip/index.vue'
import MerchMockup from '@/components/studio/MerchMockup/index.vue'
import TemplatePicker from '@/components/studio/TemplatePicker/index.vue'
import { studioApi } from '@/api/studio'
import { useAction, useAsync } from '@/composables/useAsync'
import { kitVars } from '@/lib/design'
import { relative } from '@/lib/format'

const route = useRoute()
const router = useRouter()
const { data: designs, reload } = useAsync(() => studioApi.list())
const removing = ref(null)
const { data: catalog } = useAsync(() => studioApi.catalog())
const { busy, run } = useAction()
const picking = ref(false)
const picker = ref(null)
const tab = ref(['lanyard', 'merch'].includes(route.query.tab) ? route.query.tab : 'card')
const choosingMerch = ref(false)
const list = computed(() => (designs.value ?? []).filter((d) => d.kind === tab.value))
const counts = computed(() => Object.fromEntries(['card', 'lanyard', 'merch'].map((k) => [k, (designs.value ?? []).filter((d) => d.kind === k).length])))
const merchGroups = computed(() => [
  { label: 'Pakaian', items: (catalog.value?.merch ?? []).filter((p) => p.category === 'apparel') },
  { label: 'Merchandise', items: (catalog.value?.merch ?? []).filter((p) => p.category === 'merch') },
].filter((g) => g.items.length))
// sisi kosong berwarna putih untuk gambar produk di pemilih
const blank = (p) => ({ size: { w: p.print_w_mm, h: p.print_h_mm, bleed: 0 }, background: { type: 'color', value: 'white' }, layers: [] })
const TAB_TEXT = {
  card: 'QR code, ikon NFC, dan nama TapCard selalu ada di setiap kartu.',
  lanyard: 'Pola tali diulang sepanjang 90 cm. Pasangkan dengan kartu agar brand kit & pratinjau tersinkron.',
  merch: 'Jaket, kaos, tote bag, mug, dan topi. Editor sudah bisa dipakai — pemesanan cetaknya segera hadir.',
}
const EMPTY = {
  card: ['Belum ada desain kartu. Mulai dari kartu kosong atau salah satu dari 8 template minimalis.', 'Pilih titik awal'],
  lanyard: ['Belum ada desain lanyard. Buat dari kartu (tab Lanyard di editor kartu) agar langsung serasi, atau mulai dari nol.', 'Buat lanyard'],
  merch: ['Belum ada desain merchandise. Pilih produk — jaket, kaos, tote bag, mug, atau topi — lalu desain dengan brand kit Anda.', 'Pilih produk'],
}
function startEmpty() {
  if (tab.value === 'card') picking.value = true
  else if (tab.value === 'lanyard') createLanyard()
  else choosingMerch.value = true
}
const vars = (d) => ({ ...(catalog.value?.variables ?? {}), ...kitVars(d.kit) })

async function create(choice = picker.value?.choice) {
  if (!choice?.template && !choice?.from) return
  const res = await run(() => studioApi.create(choice)).catch(() => null)
  if (res) router.push(`/designs/${res.data.id}`)
}
async function createLanyard() {
  const res = await run(() => studioApi.create({ kind: 'lanyard' })).catch(() => null)
  if (res) router.push(`/lanyards/${res.data.id}`)
}
async function createMerch(product) {
  const res = await run(() => studioApi.create({ kind: 'merch', product: product.key })).catch(() => null)
  if (res) router.push(`/merch/${res.data.id}`)
}
async function destroy() {
  const d = removing.value
  await run(() => studioApi.remove(d.id), 'Desain dihapus').catch(() => null) // run() sudah menampilkan toast galat
  removing.value = null
  reload()
}
const open = (d) => ({ lanyard: `/lanyards/${d.id}`, merch: `/merch/${d.id}` })[d.kind] ?? `/designs/${d.id}`
</script>

<template>
  <PageHeader eyebrow="Design Studio" title="Desain kartu, lanyard & merchandise">
    <!-- ponsel: satu tombol sesuai tab aktif; desktop: ketiganya -->
    <AppButton class="sm:!hidden" :loading="busy" :disabled="!catalog" @click="startEmpty"><Plus class="size-5" /> {{ { card: 'Kartu', lanyard: 'Lanyard', merch: 'Merchandise' }[tab] }} baru</AppButton>
    <AppButton variant="secondary" class="max-sm:!hidden" :disabled="!catalog" @click="choosingMerch = true"><Plus class="size-5" /> Merchandise baru</AppButton>
    <AppButton variant="secondary" class="max-sm:!hidden" :loading="busy && tab === 'lanyard'" :disabled="!catalog" @click="createLanyard"><Plus class="size-5" /> Lanyard baru</AppButton>
    <AppButton class="max-sm:!hidden" :disabled="!catalog" @click="picking = true"><Plus class="size-5" /> Kartu baru</AppButton>
  </PageHeader>
  <div class="flex flex-wrap items-center justify-between gap-3">
    <AppSegmented v-model="tab" label="Jenis desain" :options="[{ value: 'card', label: `Kartu (${counts.card})` }, { value: 'lanyard', label: `Lanyard (${counts.lanyard})` }, { value: 'merch', label: `Merchandise (${counts.merch})` }]" />
    <p class="text-sm text-muted">{{ TAB_TEXT[tab] }}</p>
  </div>

  <div v-if="!designs" class="grid gap-4 md:grid-cols-3"><AppSkeleton v-for="i in 3" :key="i" class="!h-56" /></div>
  <AppEmpty
    v-else-if="!list.length"
    :text="EMPTY[tab][0]"
  >
    <AppButton :disabled="!catalog" @click="startEmpty">{{ EMPTY[tab][1] }}</AppButton>
  </AppEmpty>
  <div v-else :class="['grid gap-4', tab === 'lanyard' ? 'lg:grid-cols-2' : 'sm:grid-cols-2 lg:grid-cols-3']">
    <article v-for="d in list" :key="d.id" class="relative grid content-start gap-3 rounded-md border border-line bg-surface p-4 hover:bg-surface-2">
      <div v-if="d.kind === 'card'" :class="d.orientation === 'portrait' ? 'mx-auto w-1/2' : ''">
        <FlipCard :front="d.front" :back="d.back" :vars="vars(d)" :kit="d.kit" :qr="catalog?.qr" hover-flip />
      </div>
      <div v-else-if="d.kind === 'merch'" class="mx-auto w-3/4"><MerchMockup :silhouette="d.merch_spec?.silhouette" :side="d.front" :kit="d.kit" :vars="vars(d)" /></div>
      <div v-else class="overflow-hidden rounded-sm"><LanyardStrip :side="d.front" :kit="d.kit" :vars="vars(d)" :length="260" :scale="2" /></div>
      <div class="flex flex-wrap items-center justify-between gap-2">
        <span class="grid min-w-0 flex-1">
          <RouterLink :to="open(d)" class="truncate font-medium text-fg no-underline after:absolute after:inset-0">{{ d.name }}</RouterLink>
          <span class="text-sm text-muted">
            <template v-if="d.kind === 'card'">{{ d.orientation === 'portrait' ? 'Potret' : 'Lanskap' }}{{ d.lanyard ? ' · lubang lanyard' : '' }}</template>
            <template v-else-if="d.kind === 'merch'">{{ d.merch_spec?.name }} · {{ d.merch_spec?.print_sides === 2 ? 'depan & belakang' : '1 sisi' }}</template>
            <template v-else>{{ d.lanyard_spec?.width_mm }} mm · {{ d.lanyard_spec?.print_sides }} sisi</template>
            · {{ relative(d.updated_at) }}
          </span>
          <span v-if="d.paired" class="inline-flex items-center gap-1 text-xs text-accent-text"><Link2 class="size-3.5" /> {{ d.kind === 'card' ? 'Lanyard' : 'Kartu' }}: {{ d.paired.name }}</span>
        </span>
        <AppBadge v-if="d.kind === 'merch'" tone="info">Cetak segera hadir</AppBadge>
        <AppBadge v-else :tone="d.validated ? 'ok' : 'warn'">{{ d.validated ? 'Siap cetak' : 'Perlu cek' }}</AppBadge>
      </div>
      <div class="relative z-10 flex flex-wrap gap-2">
        <AppButton variant="secondary" :to="open(d)"><Pencil class="size-4" /> Edit</AppButton>
        <AppButton v-if="d.validated && d.kind !== 'merch'" :to="{ path: '/orders/new', query: { design: d.id } }"><ShoppingCart class="size-4" /> Pesan cetak</AppButton>
        <AppBadge v-if="d.ordered" tone="outline" class="self-center">Sudah dipesan</AppBadge>
        <AppButton v-else variant="ghost" :aria-label="`Hapus ${d.name}`" @click="removing = d"><Trash2 class="size-4" /> Hapus</AppButton>
      </div>
    </article>
  </div>

  <AppModal v-if="removing" size="sm" title="Hapus desain?" :description="`“${removing.name}” akan dihapus permanen.${removing.paired ? ' Desain pasangannya tetap ada.' : ''}`" @close="removing = null">
    <template #footer>
      <AppButton variant="secondary" @click="removing = null">Batal</AppButton>
      <AppButton variant="danger" :loading="busy" @click="destroy">Hapus desain</AppButton>
    </template>
  </AppModal>

  <AppModal v-if="choosingMerch && catalog" size="lg" title="Merchandise baru" description="Pilih produk. Desain bisa disimpan sekarang; pemesanan cetak merchandise segera hadir." @close="choosingMerch = false">
    <section v-for="g in merchGroups" :key="g.label" class="grid gap-2">
      <h3 class="label">{{ g.label }}</h3>
      <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <button
          v-for="p in g.items" :key="p.key" type="button" :disabled="busy"
          class="grid cursor-pointer content-start gap-2 rounded-md border border-line p-3 text-left hover:bg-surface-2 disabled:cursor-wait disabled:opacity-60"
          @click="createMerch(p)"
        >
          <span class="mx-auto block w-4/5"><MerchMockup :silhouette="p.silhouette" :side="blank(p)" show-area /></span>
          <span class="flex items-center justify-between gap-2"><span class="font-semibold">{{ p.name }}</span><AppBadge v-if="p.coming_soon" tone="info">Segera</AppBadge></span>
          <span class="text-sm text-muted">Area cetak {{ p.print_w_mm / 10 }} × {{ p.print_h_mm / 10 }} cm · {{ p.print_sides === 2 ? '2 sisi' : '1 sisi' }}</span>
        </button>
      </div>
    </section>
    <template #footer>
      <AppButton variant="secondary" @click="choosingMerch = false">Batal</AppButton>
    </template>
  </AppModal>

  <AppModal v-if="picking && catalog" size="lg" title="Kartu baru" description="Mulai dari kartu kosong, template TapCard, atau template milik Anda. Semua bisa diubah penuh di editor." @close="picking = false">
    <TemplatePicker ref="picker" :catalog="catalog" @pick="create" />
    <template #footer>
      <AppButton variant="secondary" @click="picking = false">Batal</AppButton>
      <AppButton :loading="busy" @click="create()">Mulai mendesain</AppButton>
    </template>
  </AppModal>
</template>
