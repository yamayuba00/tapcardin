<script setup>
// Webhook keluar workspace perusahaan: endpoint + kesehatan, rahasia (tampil sekali), uji kirim, log, dokumentasi.
// Paket tanpa fitur Webhook (selain Business) melihat ajakan upgrade.
import { computed, ref } from 'vue'
import { Copy, KeyRound, Pencil, Plus, Send, Trash2, Webhook } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppMenu from '@/components/ui/AppMenu/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import DeliveryLog from '@/components/webhooks/DeliveryLog/index.vue'
import EndpointForm from '@/components/webhooks/EndpointForm/index.vue'
import WebhookDocs from '@/components/webhooks/WebhookDocs/index.vue'
import { webhooksApi } from '@/api/workspace'
import { useAction, useAsync } from '@/composables/useAsync'
import { dateTime } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'

const auth = useAuthStore()
const toast = useToastStore()
const allowed = computed(() => auth.can('integrations.manage'))
const { data: endpoints, meta, reload } = useAsync(() => webhooksApi.list(), { immediate: allowed.value })
const { data: catalog } = useAsync(() => webhooksApi.catalog(), { immediate: allowed.value })
const { busy, run } = useAction()

const editing = ref(null) // null = tertutup, {} = baru, endpoint = ubah
const secret = ref(null) // { url, secret } — hanya tampil sekali (modal)
const revealed = ref(null) // rahasia terakhir dibuat di sesi ini; dipakai mengisi contoh dokumentasi
const removing = ref(null)
const selected = ref(null)
const log = ref(null)
const current = computed(() => endpoints.value?.find((e) => e.id === selected.value) ?? endpoints.value?.[0] ?? null)
const atLimit = computed(() => (endpoints.value?.length ?? 0) >= (meta.value?.max ?? 10))

function saved(e) {
  const fresh = !editing.value?.id
  editing.value = null
  if (fresh && e.secret) {
    secret.value = { url: e.url, secret: e.secret }
    revealed.value = { url: e.url, secret: e.secret }
  }
  selected.value = e.id
  reload()
}
async function test(e) {
  const d = (await run(() => webhooksApi.test(e.id)).catch(() => null))?.data
  if (!d) return
  d.status === 'success' ? toast.ok(`Uji kirim berhasil — HTTP ${d.http_status}, ${d.duration_ms} ms.`) : toast.error(d.error || 'Uji kirim gagal.')
  selected.value = e.id
  log.value?.reload()
}
async function rotate(e) {
  const res = await run(() => webhooksApi.rotate(e.id), 'Rahasia baru dibuat. Rahasia lama langsung tidak berlaku.').catch(() => null)
  if (res) {
    secret.value = { url: e.url, secret: res.data.secret }
    revealed.value = { url: e.url, secret: res.data.secret }
    reload()
  }
}
async function setActive(e, on) {
  await run(() => webhooksApi.update(e.id, { is_active: on }), on ? 'Endpoint diaktifkan.' : 'Endpoint dinonaktifkan.').catch(() => null)
  reload()
}
async function remove() {
  await run(() => webhooksApi.remove(removing.value.id), 'Endpoint dihapus.').catch(() => null)
  removing.value = null
  selected.value = null
  reload()
}
async function copy(text) {
  try { await navigator.clipboard.writeText(text); toast.ok('Disalin.') } catch { toast.error('Tidak bisa menyalin.') }
}
const menu = (e) => [
  { label: 'Ubah', icon: Pencil, onSelect: () => (editing.value = e) },
  { label: e.is_active ? 'Nonaktifkan' : 'Aktifkan', onSelect: () => setActive(e, !e.is_active) },
  { label: 'Buat rahasia baru', icon: KeyRound, onSelect: () => rotate(e) },
  { label: 'Hapus', icon: Trash2, danger: true, onSelect: () => (removing.value = e) },
]
const health = (e) => (e.success_rate_7d == null ? 'Belum ada pengiriman 7 hari terakhir' : `Sukses ${e.success_rate_7d}% dari ${e.deliveries_7d} kiriman (7 hari)`)
</script>

<template>
  <PageHeader eyebrow="Perusahaan" title="Webhook">
    <template #meta><p class="text-sm text-muted">Kirim event workspace (lead, kartu, karyawan, tap) ke sistem Anda secara realtime — ERP, CRM, Zapier, n8n, atau server sendiri.</p></template>
    <AppButton v-if="allowed && catalog" :disabled="atLimit" :title="atLimit ? 'Batas endpoint tercapai' : undefined" @click="editing = {}"><Plus class="size-5" /> Tambah endpoint</AppButton>
  </PageHeader>

  <article v-if="!allowed" class="grid max-w-2xl gap-3 rounded-md border border-line bg-surface p-5">
    <div class="flex items-center gap-3">
      <span class="grid size-10 place-items-center rounded-md bg-surface-2 text-accent-text"><Webhook class="size-5" /></span>
      <h2 class="font-display text-xl font-bold">Webhook tersedia di paket Business</h2>
    </div>
    <p class="text-muted">Paket saat ini {{ auth.workspace?.plan_name }}. Upgrade untuk mengirim lead baru, Tukar Kontak, status kartu, dan perubahan karyawan ke sistem Anda — dengan tanda tangan HMAC, kirim ulang otomatis, dan log pengiriman.</p>
    <div><AppButton to="/billing">Lihat paket</AppButton></div>
  </article>

  <template v-else>
    <AppSkeleton v-if="!endpoints || !catalog" class="!h-48" />
    <template v-else>
      <section v-if="!endpoints.length" class="grid max-w-2xl gap-3 rounded-md border border-dashed border-line-strong p-6">
        <h2 class="font-display text-xl font-bold">Belum ada endpoint</h2>
        <p class="text-muted">Tambahkan URL https sistem Anda, pilih event, lalu klik <b>Kirim uji</b>. Contoh payload & cara verifikasi tanda tangan ada di Dokumentasi di bawah.</p>
        <div><AppButton @click="editing = {}"><Plus class="size-5" /> Tambah endpoint</AppButton></div>
      </section>

      <div v-else class="grid gap-3">
        <article v-for="e in endpoints" :key="e.id"
          :class="['grid gap-3 rounded-md border bg-surface p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start', current?.id === e.id ? 'border-accent' : 'border-line']">
          <div class="grid min-w-0 gap-1.5">
            <div class="flex flex-wrap items-center gap-2">
              <AppBadge :tone="e.is_active ? 'ok' : 'danger'">{{ e.is_active ? 'Aktif' : 'Nonaktif' }}</AppBadge>
              <span class="truncate font-mono text-sm" :title="e.url">{{ e.url }}</span>
            </div>
            <p v-if="e.description" class="text-sm">{{ e.description }}</p>
            <p v-if="e.disabled_reason" class="text-sm text-danger">Dinonaktifkan otomatis {{ dateTime(e.disabled_at) }} — {{ e.disabled_reason }}</p>
            <p class="text-xs text-muted">
              {{ health(e) }}
              <template v-if="e.last_success_at"> · terakhir sukses {{ dateTime(e.last_success_at) }}</template>
              <template v-if="e.last_failure_at"> · terakhir gagal {{ dateTime(e.last_failure_at) }}</template>
            </p>
            <p class="flex flex-wrap gap-1">
              <span v-for="k in e.events" :key="k" class="rounded-sm bg-surface-2 px-2 py-0.5 font-mono text-xs">{{ k }}</span>
            </p>
            <p class="font-mono text-xs text-muted">Rahasia: {{ e.secret_hint }}</p>
          </div>
          <div class="flex flex-wrap items-center gap-1 sm:justify-end">
            <AppButton variant="ghost" :aria-pressed="current?.id === e.id" @click="selected = e.id">Log</AppButton>
            <AppButton variant="secondary" :disabled="busy" @click="test(e)"><Send class="size-4" /> Kirim uji</AppButton>
            <AppMenu :items="menu(e)" :label="`Aksi ${e.url}`" />
          </div>
        </article>
      </div>

      <AppPanel v-if="current" :title="`Log pengiriman — ${current.description || current.url}`">
        <DeliveryLog ref="log" :key="current.id" :endpoint="current" :events="catalog.events" />
      </AppPanel>

      <AppPanel title="Dokumentasi integrasi">
        <WebhookDocs :catalog="catalog" :endpoint-url="current?.url" :secret="current && revealed?.url === current.url ? revealed.secret : ''" @rotate="rotate(current)" />
      </AppPanel>
    </template>
  </template>

  <EndpointForm v-if="editing && catalog" :endpoint="editing.id ? editing : null" :events="catalog.events" @saved="saved" @close="editing = null" />

  <AppModal v-if="secret" size="sm" title="Simpan rahasia ini sekarang" description="Rahasia hanya ditampilkan sekali. Pakai untuk memverifikasi header X-TapCard-Signature." :dismissible="false" @close="secret = null">
    <div class="grid gap-3">
      <p class="truncate font-mono text-xs text-muted">{{ secret.url }}</p>
      <div class="flex items-center gap-2 rounded-md bg-surface-2 p-3">
        <code class="min-w-0 flex-1 font-mono text-sm break-all select-all">{{ secret.secret }}</code>
        <button type="button" class="grid size-9 flex-none cursor-pointer place-items-center rounded-md text-muted hover:text-fg" aria-label="Salin rahasia" @click="copy(secret.secret)"><Copy class="size-4" /></button>
      </div>
    </div>
    <template #footer><AppButton @click="secret = null">Sudah saya simpan</AppButton></template>
  </AppModal>

  <AppModal v-if="removing" size="sm" title="Hapus endpoint?" :description="`${removing.url} tidak akan menerima event lagi. Log pengirimannya ikut terhapus.`" @close="removing = null">
    <template #footer>
      <AppButton variant="secondary" @click="removing = null">Batal</AppButton>
      <AppButton variant="danger" :loading="busy" @click="remove">Hapus</AppButton>
    </template>
  </AppModal>
</template>
