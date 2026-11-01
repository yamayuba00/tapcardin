<script setup>
// U-12 — desain kartu & lanyard milik pengguna. Kartu baru dari kartu kosong / 8 template / template saya;
// lanyard baru berdiri sendiri atau berpasangan dengan kartu (brand kit bersama, pratinjau gabungan).
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
const tab = ref(route.query.tab === 'lanyard' ? 'lanyard' : 'card')
const list = computed(() => (designs.value ?? []).filter((d) => d.kind === tab.value))
const counts = computed(() => ({ card: (designs.value ?? []).filter((d) => d.kind === 'card').length, lanyard: (designs.value ?? []).filter((d) => d.kind === 'lanyard').length }))
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
async function destroy() {
  const d = removing.value
  await run(() => studioApi.remove(d.id), 'Desain dihapus').catch(() => null) // run() sudah menampilkan toast galat
  removing.value = null
  reload()
}
const open = (d) => (d.kind === 'lanyard' ? `/lanyards/${d.id}` : `/designs/${d.id}`)
</script>

<template>
  <PageHeader eyebrow="Design Studio" title="Desain kartu & lanyard">
    <AppButton variant="secondary" :loading="busy && tab === 'lanyard'" :disabled="!catalog" @click="createLanyard"><Plus class="size-5" /> Lanyard baru</AppButton>
    <AppButton :disabled="!catalog" @click="picking = true"><Plus class="size-5" /> Kartu baru</AppButton>
  </PageHeader>
  <div class="flex flex-wrap items-center justify-between gap-3">
    <AppSegmented v-model="tab" label="Jenis desain" :options="[{ value: 'card', label: `Kartu (${counts.card})` }, { value: 'lanyard', label: `Lanyard (${counts.lanyard})` }]" />
    <p class="text-sm text-muted">{{ tab === 'card' ? 'QR code, ikon NFC, dan nama TapCard selalu ada di setiap kartu.' : 'Pola tali diulang sepanjang 90 cm. Pasangkan dengan kartu agar brand kit & pratinjau tersinkron.' }}</p>
  </div>

  <div v-if="!designs" class="grid gap-4 md:grid-cols-3"><AppSkeleton v-for="i in 3" :key="i" class="!h-56" /></div>
  <AppEmpty
    v-else-if="!list.length"
    :text="tab === 'card' ? 'Belum ada desain kartu. Mulai dari kartu kosong atau salah satu dari 8 template minimalis.' : 'Belum ada desain lanyard. Buat dari kartu (tab Lanyard di editor kartu) agar langsung serasi, atau mulai dari nol.'"
  >
    <AppButton :disabled="!catalog" @click="tab === 'card' ? (picking = true) : createLanyard()">{{ tab === 'card' ? 'Pilih titik awal' : 'Buat lanyard' }}</AppButton>
  </AppEmpty>
  <div v-else :class="['grid gap-4', tab === 'card' ? 'sm:grid-cols-2 lg:grid-cols-3' : 'lg:grid-cols-2']">
    <article v-for="d in list" :key="d.id" class="relative grid content-start gap-3 rounded-md border border-line bg-surface p-4 hover:bg-surface-2">
      <div v-if="d.kind === 'card'" :class="d.orientation === 'portrait' ? 'mx-auto w-1/2' : ''">
        <FlipCard :front="d.front" :back="d.back" :vars="vars(d)" :kit="d.kit" :qr="catalog?.qr" hover-flip />
      </div>
      <div v-else class="overflow-hidden rounded-sm"><LanyardStrip :side="d.front" :kit="d.kit" :vars="vars(d)" :length="260" :scale="2" /></div>
      <div class="flex flex-wrap items-center justify-between gap-2">
        <span class="grid min-w-0 flex-1">
          <RouterLink :to="open(d)" class="truncate font-medium text-fg no-underline after:absolute after:inset-0">{{ d.name }}</RouterLink>
          <span class="text-sm text-muted">
            <template v-if="d.kind === 'card'">{{ d.orientation === 'portrait' ? 'Potret' : 'Lanskap' }}{{ d.lanyard ? ' · lubang lanyard' : '' }}</template>
            <template v-else>{{ d.lanyard_spec?.width_mm }} mm · {{ d.lanyard_spec?.print_sides }} sisi</template>
            · {{ relative(d.updated_at) }}
          </span>
          <span v-if="d.paired" class="inline-flex items-center gap-1 text-xs text-accent-text"><Link2 class="size-3.5" /> {{ d.kind === 'card' ? 'Lanyard' : 'Kartu' }}: {{ d.paired.name }}</span>
        </span>
        <AppBadge :tone="d.validated ? 'ok' : 'warn'">{{ d.validated ? 'Siap cetak' : 'Perlu cek' }}</AppBadge>
      </div>
      <div class="relative z-10 flex flex-wrap gap-2">
        <AppButton variant="secondary" :to="open(d)"><Pencil class="size-4" /> Edit</AppButton>
        <AppButton v-if="d.validated" :to="{ path: '/orders/new', query: { design: d.id } }"><ShoppingCart class="size-4" /> Pesan cetak</AppButton>
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

  <AppModal v-if="picking && catalog" size="lg" title="Kartu baru" description="Mulai dari kartu kosong, template TapCard, atau template milik Anda. Semua bisa diubah penuh di editor." @close="picking = false">
    <TemplatePicker ref="picker" :catalog="catalog" @pick="create" />
    <template #footer>
      <AppButton variant="secondary" @click="picking = false">Batal</AppButton>
      <AppButton :loading="busy" @click="create()">Mulai mendesain</AppButton>
    </template>
  </AppModal>
</template>
