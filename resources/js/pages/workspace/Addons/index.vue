<script setup>
// Add-on — menu sendiri, terpisah dari Paket & tagihan. Fitur berbayar di luar paket (mis. Integrasi CRM).
// Status "Segera hadir": workspace mendaftar minat dan dikabari saat add-on bisa dibeli. Satu pendaftaran per
// workspace; bisa dibatalkan.
import { reactive, ref } from 'vue'
import { BellRing, Check } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { addonsApi } from '@/api/workspace'
import { useAction, useAsync } from '@/composables/useAsync'
import { rupiah } from '@/lib/format'

const { data: addons } = useAsync(() => addonsApi.list())
const { busy, run } = useAction()
const asking = ref(null) // add-on yang sedang didaftarkan
const form = reactive({ note: '' })

function replace(a) {
  addons.value = addons.value.map((x) => (x.key === a.key ? a : x))
}
async function register() {
  const res = await run(() => addonsApi.interest(asking.value.key, form.note || null), 'Terima kasih! Kami kabari begitu add-on ini tersedia.').catch(() => null)
  if (res) { replace(res.data); asking.value = null; form.note = '' }
}
async function withdraw(a) {
  const res = await run(() => addonsApi.withdraw(a.key), 'Pendaftaran dibatalkan.').catch(() => null)
  if (res) replace(res.data)
}
</script>

<template>
  <PageHeader eyebrow="Lainnya" title="Add-on">
    <template #meta><p class="text-sm text-muted">Fitur tambahan dengan pembayaran terpisah dari paket — pasang hanya yang Anda perlukan.</p></template>
  </PageHeader>

  <AppSkeleton v-if="!addons" class="!h-48" />
  <p v-else-if="!addons.length" class="rounded-md border border-line bg-surface p-5 text-muted">Belum ada add-on yang ditawarkan.</p>
  <div v-else class="grid gap-4 md:grid-cols-2">
    <article v-for="a in addons" :key="a.key" class="grid content-start gap-4 rounded-md border border-line bg-surface p-5">
      <div class="flex items-start justify-between gap-3">
        <div class="grid gap-1">
          <h2 class="font-display text-xl font-bold">{{ a.name }}</h2>
          <span v-if="a.price_monthly" class="text-sm text-muted">Perkiraan {{ rupiah(a.price_monthly) }} / bulan per workspace</span>
        </div>
        <AppBadge :tone="a.status === 'coming_soon' ? 'info' : 'ok'">{{ a.status === 'coming_soon' ? 'Segera hadir' : 'Tersedia' }}</AppBadge>
      </div>
      <p class="text-muted">{{ a.description }}</p>
      <ul class="grid gap-2 text-sm">
        <li v-for="f in a.features" :key="f" class="flex gap-2"><Check class="size-4 flex-none translate-y-0.5 text-ok" /> {{ f }}</li>
      </ul>
      <div v-if="a.status === 'coming_soon'" class="flex flex-wrap items-center gap-3 border-t border-line pt-4">
        <template v-if="a.interested_at">
          <span class="flex items-center gap-2 text-sm text-ok"><BellRing class="size-4" /> Anda akan dikabari saat tersedia</span>
          <AppButton variant="ghost" :loading="busy" @click="withdraw(a)">Batalkan</AppButton>
        </template>
        <AppButton v-else @click="asking = a"><BellRing class="size-5" /> Beri tahu saya</AppButton>
      </div>
    </article>
  </div>

  <AppModal v-if="asking" size="sm" :title="`Kabari saya: ${asking.name}`" description="Kami kirim notifikasi & email ke workspace ini begitu add-on bisa dibeli. Belum ada tagihan apa pun." @close="asking = null">
    <form id="addon-interest" @submit.prevent="register">
      <AppField label="CRM / alat yang Anda pakai (opsional)" hint="mis. HubSpot, Google Sheets, Zoho"><AppInput v-model="form.note" maxlength="200" /></AppField>
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="asking = null">Batal</AppButton>
      <AppButton type="submit" form="addon-interest" :loading="busy">Daftarkan minat</AppButton>
    </template>
  </AppModal>
</template>
