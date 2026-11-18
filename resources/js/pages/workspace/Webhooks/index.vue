<script setup>
// Webhook — segera hadir. Owner/admin mendaftar minat per workspace; dikabari saat fitur rilis (paket Business).
import { computed, ref } from 'vue'
import { BellRing, Check, Webhook } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { addonsApi } from '@/api/workspace'
import { useAction, useAsync } from '@/composables/useAsync'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const { data } = useAsync(() => addonsApi.list('webhook'))
const item = computed(() => data.value?.[0] ?? null)
const inPlan = computed(() => auth.can('integrations.manage'))
const { busy, run } = useAction()
const note = ref('')

async function register() {
  const res = await run(() => addonsApi.interest(item.value.key, note.value || null), 'Terima kasih! Kami kabari begitu Webhook tersedia.').catch(() => null)
  if (res) { data.value = [res.data]; note.value = '' }
}
async function withdraw() {
  const res = await run(() => addonsApi.withdraw(item.value.key), 'Pendaftaran dibatalkan.').catch(() => null)
  if (res) data.value = [res.data]
}
</script>

<template>
  <PageHeader eyebrow="Perusahaan" title="Webhook">
    <template #meta><p class="text-sm text-muted">Kirim event workspace ke sistem Anda sendiri secara realtime.</p></template>
  </PageHeader>

  <AppSkeleton v-if="!data" class="!h-48" />
  <p v-else-if="!item" class="rounded-md border border-line bg-surface p-5 text-muted">Webhook belum tersedia.</p>
  <article v-else class="grid max-w-2xl content-start gap-4 rounded-md border border-line bg-surface p-5">
    <div class="flex items-start justify-between gap-3">
      <div class="flex items-center gap-3">
        <span class="grid size-10 place-items-center rounded-md bg-surface-2 text-accent-text"><Webhook class="size-5" /></span>
        <h2 class="font-display text-xl font-bold">{{ item.name }}</h2>
      </div>
      <AppBadge tone="info">Segera hadir</AppBadge>
    </div>
    <p class="text-muted">{{ item.description }}</p>
    <ul class="grid gap-2 text-sm">
      <li v-for="f in item.features" :key="f" class="flex gap-2"><Check class="size-4 flex-none translate-y-0.5 text-ok" /> {{ f }}</li>
    </ul>
    <p class="rounded-md border border-line bg-surface-2 px-4 py-3 text-sm">
      Tersedia di paket <b>Business</b>.
      <span v-if="inPlan">Paket workspace ini sudah mencakup Webhook — langsung bisa dipakai saat rilis.</span>
      <span v-else>Paket saat ini {{ auth.workspace?.plan_name }}; upgrade ke Business saat Webhook rilis.</span>
    </p>
    <div class="grid gap-3 border-t border-line pt-4">
      <template v-if="item.interested_at">
        <div class="flex flex-wrap items-center gap-3">
          <span class="flex items-center gap-2 text-sm text-ok"><BellRing class="size-4" /> Anda akan dikabari saat Webhook tersedia</span>
          <AppButton variant="ghost" :loading="busy" @click="withdraw">Batalkan</AppButton>
        </div>
      </template>
      <form v-else class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end" @submit.prevent="register">
        <AppField label="Sistem tujuan (opsional)" hint="mis. ERP internal, Zapier, n8n"><AppInput v-model="note" maxlength="200" /></AppField>
        <AppButton type="submit" :loading="busy"><BellRing class="size-5" /> Beri tahu saya</AppButton>
      </form>
    </div>
  </article>
</template>
