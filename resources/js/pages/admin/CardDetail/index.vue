<script setup>
// PL-07 detail — riwayat status, tap terbaru, ubah status (alasan wajib), terbitkan ulang kode aktivasi.
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CopyPlus, Eye, RefreshCw, UserCheck } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { useAction, useAsync } from '@/composables/useAsync'
import { dateTime } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const token = route.params.token
const { data: card } = useAsync(() => adminApi.card(token))
const { busy, run } = useAction()
const status = ref('')
const reason = ref('')
const errors = ref({})
const newCode = ref(null)

const owner = ref('')

watch(card, (c) => {
  status.value = c?.targets[0]?.value ?? ''
  owner.value = c?.assigned?.email ?? ''
})

async function assign() {
  errors.value = {}
  try {
    card.value = (await run(() => adminApi.assignCard(token, owner.value || null), owner.value ? 'Pemilik kartu ditandai.' : 'Penandaan pemilik dilepas.')).data
  } catch (e) { errors.value = e.fields || {} }
}

async function changeStatus() {
  errors.value = {}
  try {
    card.value = (await run(() => adminApi.cardStatus(token, status.value, reason.value), 'Status kartu diubah.')).data
    reason.value = ''
  } catch (e) { errors.value = e.fields }
}
async function replacementBatch() {
  const res = await run(() => adminApi.replacementBatch(token), 'Batch pengganti dibuat.').catch(() => null)
  if (res) router.push(`/batches/${res.data.batch.id}`)
}
async function reissue() {
  const res = await run(() => adminApi.reissueCode(token)).catch(() => null)
  if (res) newCode.value = res.data.code
}
</script>

<template>
  <AppSkeleton v-if="!card" class="!h-60" />
  <template v-else>
    <PageHeader :title="card.token" back="/cards" back-label="Kartu">
      <template #meta>
        <div class="flex flex-wrap gap-2">
          <AppBadge :tone="card.tone">{{ card.status_label }}</AppBadge>
          <AppBadge tone="outline">{{ card.chip_type }}</AppBadge>
          <AppBadge tone="outline">{{ card.short_code }}</AppBadge>
        </div>
      </template>
      <AppButton variant="secondary" :href="card.url" target="_blank" rel="noopener"><Eye class="size-5" /> Buka resolver</AppButton>
    </PageHeader>

    <AppPanel v-if="newCode" title="Kode aktivasi baru">
      <p class="rounded-md bg-surface-2 p-4 text-center font-mono text-[28px] tracking-[.2em]">{{ newCode }}</p>
      <p class="mt-2 text-sm text-muted">Kirim ke pemilik kartu lewat kanal resmi. Kode ini tidak akan ditampilkan lagi.</p>
    </AppPanel>

    <AppPanel v-if="card.replacement" title="Kartu pengganti">
      <div class="grid gap-3 text-sm">
        <p>Diminta pemilik {{ dateTime(card.replacement.requested_at) }}<template v-if="card.replacement.note"> · “{{ card.replacement.note }}”</template></p>
        <p v-if="card.replacement.batch">
          Batch <RouterLink :to="`/batches/${card.replacement.batch.id}`">{{ card.replacement.batch.code }}</RouterLink>
          <AppBadge tone="info" class="ml-2">{{ card.replacement.batch.status_label }}</AppBadge>
        </p>
        <template v-else-if="auth.can('production.manage') && card.status === 'lost'">
          <p class="text-muted">Batch 1 kartu dengan pemegang yang sama. Pemilik mengaktifkannya tanpa kode; kartu ini otomatis dicabut.</p>
          <div><AppButton :loading="busy" @click="replacementBatch"><CopyPlus class="size-5" /> Buat batch pengganti</AppButton></div>
        </template>
      </div>
    </AppPanel>

    <div class="grid gap-4 md:grid-cols-2">
      <AppPanel title="Detail">
        <dl class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
          <dt class="text-muted">Pemegang</dt><dd><RouterLink v-if="card.holder" :to="`/users/${card.holder.id}`">{{ card.holder.email }}</RouterLink><span v-else>—</span></dd>
          <dt class="text-muted">Pemilik ditandai</dt><dd><RouterLink v-if="card.assigned" :to="`/users/${card.assigned.id}`">{{ card.assigned.email }}</RouterLink><span v-else>—</span></dd>
          <dt class="text-muted">Chip terikat</dt><dd>{{ card.uid_bound ? 'Ya, UID chip tercatat' : 'Belum di-encode' }}</dd>
          <dt class="text-muted">Batch</dt><dd><RouterLink v-if="card.batch" :to="`/batches/${card.batch.id}`">{{ card.batch.code }}</RouterLink> #{{ card.sequence }}</dd>
          <dt class="text-muted">Di-encode</dt><dd>{{ dateTime(card.encoded_at) }}</dd>
          <dt class="text-muted">Lolos QC</dt><dd>{{ dateTime(card.qc_passed_at) }}</dd>
          <dt class="text-muted">Dikirim</dt><dd>{{ dateTime(card.shipped_at) }}</dd>
          <dt class="text-muted">Diaktifkan</dt><dd>{{ dateTime(card.activated_at) }}</dd>
          <dt class="text-muted">Percobaan kode</dt><dd>{{ card.activation_attempts }}<span v-if="card.activation_locked_until"> · terkunci s/d {{ dateTime(card.activation_locked_until) }}</span></dd>
        </dl>
      </AppPanel>

      <AppPanel v-if="auth.can('cards.manage') && card.can_assign" title="Pemilik kartu">
        <form class="grid gap-4" @submit.prevent="assign">
          <p class="text-sm text-muted">Tandai siapa pemilik kartu ini sebelum diaktifkan. Pemilik yang ditandai mengaktifkan tanpa kode, cukup masuk dengan emailnya. Pengguna lain tetap butuh kode aktivasi.</p>
          <AppField label="Email pemilik (kosongkan untuk melepas)" :error="errors.email?.[0]"><AppInput v-model="owner" type="email" maxlength="190" placeholder="pemilik@example.com" /></AppField>
          <div><AppButton type="submit" variant="secondary" :loading="busy"><UserCheck class="size-5" /> Simpan pemilik</AppButton></div>
        </form>
      </AppPanel>

      <AppPanel v-if="auth.can('cards.manage')" title="Ubah status">
        <form v-if="card.targets.length" class="grid gap-4" @submit.prevent="changeStatus">
          <AppField label="Status baru" :error="errors.status?.[0]"><AppSelect v-model="status" :options="card.targets" /></AppField>
          <AppField label="Alasan (wajib, tercatat di audit)" :error="errors.reason?.[0]"><AppInput v-model="reason" maxlength="200" /></AppField>
          <div><AppButton type="submit" :loading="busy">Ubah status</AppButton></div>
        </form>
        <p v-else class="text-muted">Kartu {{ card.status_label }} tidak bisa diubah lagi.</p>
        <div v-if="card.can_reissue" class="mt-4 border-t border-line pt-4">
          <AppButton variant="secondary" :loading="busy" @click="reissue"><RefreshCw class="size-5" /> Terbitkan ulang kode aktivasi</AppButton>
        </div>
      </AppPanel>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
      <AppPanel title="Riwayat status">
        <ul>
          <li v-for="(h, i) in card.history" :key="i" class="flex justify-between gap-3 border-b border-line py-3 last:border-b-0">
            <div><span class="block font-medium">{{ h.from }} → {{ h.to }}</span><span class="text-sm text-muted">{{ h.reason }}</span></div>
            <span class="font-mono text-sm">{{ dateTime(h.at) }}</span>
          </li>
          <li v-if="!card.history.length" class="text-muted">Belum ada perubahan status.</li>
        </ul>
      </AppPanel>
      <AppPanel title="Tap terbaru">
        <ul>
          <li v-for="(t, i) in card.taps" :key="i" class="flex justify-between gap-3 border-b border-line py-3 last:border-b-0">
            <div><span class="block font-medium uppercase">{{ t.source }} · {{ t.device }}</span><span class="text-sm text-muted">{{ t.city ?? 'Kota tidak diketahui' }}</span></div>
            <span class="font-mono text-sm">{{ dateTime(t.at) }}</span>
          </li>
          <li v-if="!card.taps.length" class="text-muted">Belum ada tap.</li>
        </ul>
      </AppPanel>
    </div>
  </template>
</template>
