<script setup>
// U-03 — ganti profil aktif (F-12), jeda/lanjutkan, lapor hilang & ditemukan (F-13), minta kartu pengganti, bagikan.
// Kartu digital: tanpa kode cetak & lapor hilang — dibagikan lewat QR layar & tautan.
import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { Check, CircleAlert, Copy, Eye, Pause, Pencil, Play, QrCode, RefreshCw, Share2, Smartphone } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import CardVisual from '@/components/cards/CardVisual/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { cardsApi } from '@/api/workspace'
import { useAction, useAsync } from '@/composables/useAsync'
import { dateTime, num, relative } from '@/lib/format'
import { useToastStore } from '@/stores/toast'

const route = useRoute()
const toast = useToastStore()
const { data: card } = useAsync(() => cardsApi.get(route.params.token))
const { busy, run } = useAction()
const label = ref('')
const confirm = ref(null) // 'lost' | 'paused' | 'replace'
const replaceNote = ref('')

watch(card, (c) => { if (c) label.value = c.label || '' }, { once: true })

async function patch(body, message) {
  const res = await run(() => cardsApi.update(route.params.token, body), message).catch(() => null)
  if (res) card.value = { ...card.value, ...res.data }
  confirm.value = null
}
async function requestReplacement() {
  const res = await run(() => cardsApi.requestReplacement(route.params.token, replaceNote.value || null), 'Permintaan kartu pengganti terkirim').catch(() => null)
  if (res) card.value = { ...card.value, ...res.data }
  confirm.value = null
}
const can = (s) => card.value?.allowed.includes(s)
async function copy() {
  try { await navigator.clipboard.writeText(card.value.url); toast.ok('Tautan disalin') } catch { toast.push(card.value.url) }
}
</script>

<template>
  <div v-if="!card" class="grid gap-4" aria-busy="true"><AppSkeleton class="!h-8 w-2/5" /><AppSkeleton class="!h-56" /></div>
  <template v-else>
    <PageHeader :title="card.label || 'Kartu ' + card.short_code" back="/cards" back-label="Kartu">
      <template #meta>
        <div class="flex flex-wrap gap-2">
          <AppBadge :tone="card.tone">{{ card.status_label }}</AppBadge>
          <AppBadge v-if="card.form === 'digital'" tone="info"><Smartphone class="size-3.5" /> Digital</AppBadge>
          <AppBadge v-else tone="outline">{{ card.short_code }}</AppBadge>
        </div>
      </template>
      <AppButton v-if="card.status === 'active'" :to="`/cards/${card.token}/share`"><Share2 class="size-5" /> Bagikan</AppButton>
      <AppButton v-if="card.status === 'active'" variant="secondary" :to="`/cards/${card.token}/qr`"><QrCode class="size-5" /> QR aman</AppButton>
      <AppButton variant="secondary" :href="card.url" target="_blank" rel="noopener"><Eye class="size-5" /> Lihat profil publik</AppButton>
    </PageHeader>

    <div v-if="card.status === 'lost'" class="flex gap-3 rounded-md border border-line-strong bg-surface-2 px-4 py-3 text-sm" role="alert">
      <CircleAlert class="size-5 flex-none text-danger" />
      <div class="grid gap-1">
        <span>Kartu ditandai hilang sejak {{ dateTime(card.lost_at) }}. Siapa pun yang men-tap akan melihat halaman netral.</span>
        <span v-for="(t, i) in card.lost_taps" :key="i">Di-tap {{ relative(t.at) }}{{ t.city ? ' · ' + t.city : '' }}</span>
        <span v-if="card.replacement_requested_at" class="text-muted">Kartu pengganti diminta {{ relative(card.replacement_requested_at) }}. Tim kami akan mencetaknya; kartu ini otomatis nonaktif saat pengganti diaktifkan.</span>
        <div v-else><AppButton variant="secondary" @click="confirm = 'replace'"><RefreshCw class="size-5" /> Minta kartu pengganti</AppButton></div>
      </div>
    </div>

    <AppPanel v-if="card.form === 'digital'" title="Tampilan kartu digital">
      <div class="grid gap-4">
        <p class="flex gap-3 text-sm text-muted">
          <Smartphone class="size-5 flex-none text-info" aria-hidden="true" />
          <span>Desainnya sama seperti kartu fisik, diisi dari bio kartu ini. Karena tanpa chip, QR di sisi belakang jadi pintu masuknya —
            unduh sebagai gambar untuk dikirim, dijadikan wallpaper, atau ditaruh di email signature.</span>
        </p>
        <CardVisual :token="card.token" :name="card.profile?.display_name || 'kartu'" editable />
      </div>
    </AppPanel>

    <div class="grid gap-4 md:grid-cols-2">
      <AppPanel title="Pengaturan kartu">
        <div class="grid gap-4">
          <AppField label="Nama kartu">
            <div class="flex gap-2">
              <AppInput v-model="label" maxlength="40" placeholder="mis. Kartu hitam" />
              <AppButton variant="secondary" :disabled="busy" @click="patch({ label }, 'Nama kartu disimpan')">Simpan</AppButton>
            </div>
          </AppField>
          <div v-if="card.profile" class="grid gap-2 rounded-md border border-line bg-surface-2 p-4">
            <span class="label">Bio link kartu ini</span>
            <span class="font-medium text-fg">{{ card.profile.display_name }}</span>
            <span class="text-sm text-muted">Setiap kartu punya bio sendiri. Ubah kapan saja — {{ card.form === 'digital' ? 'tautan & QR tetap sama' : 'URL di chip & QR tetap sama, tidak perlu cetak ulang' }}.</span>
            <div><AppButton :to="`/cards/${card.token}/bio`"><Pencil class="size-5" /> Atur bio link</AppButton></div>
          </div>
          <div class="flex flex-wrap gap-2">
            <AppButton v-if="can('paused')" variant="secondary" :disabled="busy" @click="confirm = 'paused'"><Pause class="size-5" /> Jeda profil</AppButton>
            <AppButton v-if="card.status === 'paused' && can('active')" :disabled="busy" @click="patch({ status: 'active' }, 'Profil aktif kembali')"><Play class="size-5" /> Lanjutkan</AppButton>
            <AppButton v-if="can('lost')" variant="ghost" :disabled="busy" @click="confirm = 'lost'"><CircleAlert class="size-5" /> Laporkan hilang</AppButton>
            <AppButton v-if="card.status === 'lost' && can('active')" :disabled="busy" @click="patch({ status: 'active' }, 'Kartu aktif kembali')"><Check class="size-5" /> Sudah ditemukan</AppButton>
          </div>
        </div>
      </AppPanel>

      <AppPanel title="30 hari terakhir">
        <div class="grid gap-4">
          <div class="grid grid-cols-3 gap-4">
            <div v-for="[k, l] in [['taps', 'Tap'], ['visitors', 'Unik'], ['saved', 'Simpan']]" :key="k" class="grid gap-2">
              <span class="label">{{ l }}</span><span class="num text-[28px] leading-none">{{ num(card.stats_30[k]) }}</span>
            </div>
          </div>
          <p class="text-sm text-muted">NFC {{ num(card.stats_30.nfc) }} · QR {{ num(card.stats_30.qr) }} · lainnya {{ num(card.stats_30.other) }}</p>
          <div class="flex items-center gap-3">
            <img :src="card.qr_svg_url" alt="QR kartu" width="96" height="96" class="flex-none rounded-sm bg-bone-0 p-1" />
            <div class="grid min-w-0 gap-2">
              <span class="truncate font-mono text-sm">{{ card.url }}</span>
              <AppButton variant="ghost" @click="copy"><Copy class="size-5" /> Salin tautan</AppButton>
            </div>
          </div>
        </div>
      </AppPanel>
    </div>

    <AppModal v-if="confirm === 'lost'" title="Laporkan kartu hilang?" @close="confirm = null">
      <p>Tap berikutnya akan menampilkan halaman netral, dan Anda akan diberi tahu waktu & kota tap tersebut. Anda bisa mengaktifkannya lagi jika kartu ditemukan.</p>
      <template #footer>
        <AppButton variant="secondary" @click="confirm = null">Batal</AppButton>
        <AppButton variant="danger" :loading="busy" @click="patch({ status: 'lost' }, 'Kartu ditandai hilang')">Ya, tandai hilang</AppButton>
      </template>
    </AppModal>
    <AppModal v-if="confirm === 'replace'" title="Minta kartu pengganti?" @close="confirm = null">
      <p>Kami cetak kartu baru dengan profil yang sama. Begitu kartu baru diaktifkan, kartu yang hilang ini tidak berlaku lagi.</p>
      <AppField label="Catatan (opsional)" hint="mis. alamat kirim berbeda."><AppInput v-model="replaceNote" maxlength="200" /></AppField>
      <template #footer>
        <AppButton variant="secondary" @click="confirm = null">Batal</AppButton>
        <AppButton :loading="busy" @click="requestReplacement">Kirim permintaan</AppButton>
      </template>
    </AppModal>
    <AppModal v-if="confirm === 'paused'" title="Jeda profil?" @close="confirm = null">
      <p>Selama dijeda, orang yang men-tap kartu ini melihat halaman "Profil sedang tidak aktif".</p>
      <template #footer>
        <AppButton variant="secondary" @click="confirm = null">Batal</AppButton>
        <AppButton :loading="busy" @click="patch({ status: 'paused' }, 'Profil dijeda')">Jeda</AppButton>
      </template>
    </AppModal>
  </template>
</template>
