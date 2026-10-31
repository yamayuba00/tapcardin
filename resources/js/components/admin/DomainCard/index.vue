<script setup>
// Satu domain kustom: status, record DNS yang harus dipasang pelanggan (salin), verifikasi, aktif/nonaktif, hapus.
import { ref } from 'vue'
import { Copy, RefreshCw, Trash2 } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import { adminApi } from '@/api/admin'
import { useAction } from '@/composables/useAsync'
import { dateTime } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'

const props = defineProps({ domain: { type: Object, required: true }, showTenant: Boolean })
const emit = defineEmits(['update', 'remove'])
const auth = useAuthStore()
const toast = useToastStore()
const { busy, run } = useAction()
const confirming = ref(false)
const STATUS = { pending: ['warn', 'Menunggu DNS'], active: ['ok', 'Aktif'], disabled: ['muted', 'Nonaktif'] }

async function copy(v) {
  try { await navigator.clipboard.writeText(v); toast.ok('Disalin.') } catch { toast.error('Tidak bisa menyalin — salin manual.') }
}
async function verify() {
  const res = await run(() => adminApi.verifyDomain(props.domain.id)).catch(() => null)
  if (!res) return
  emit('update', res.data)
  res.data.status === 'active' ? toast.ok(`${res.data.domain} terverifikasi & aktif.`) : toast.error(res.data.last_error ?? 'Belum terverifikasi.')
}
async function setStatus(status) {
  const res = await run(() => adminApi.setDomainStatus(props.domain.id, status), status === 'active' ? 'Domain diaktifkan.' : 'Domain dinonaktifkan.').catch(() => null)
  if (res) emit('update', res.data)
}
async function remove() {
  const ok = await run(() => adminApi.removeDomain(props.domain.id), 'Domain dihapus.').then(() => true).catch(() => false)
  confirming.value = false
  if (ok) emit('remove', props.domain.id)
}
</script>

<template>
  <article class="grid gap-4 rounded-lg border border-line p-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <a :href="`https://${domain.domain}`" target="_blank" rel="noopener" class="font-mono text-base font-semibold break-all">{{ domain.domain }}</a>
        <span v-if="showTenant && domain.tenant" class="block text-sm text-muted">
          <RouterLink :to="`/tenants/${domain.tenant.id}`">{{ domain.tenant.name }}</RouterLink>
        </span>
      </div>
      <AppBadge :tone="STATUS[domain.status]?.[0]">{{ STATUS[domain.status]?.[1] }}</AppBadge>
    </div>

    <div v-if="domain.status !== 'active'" class="grid gap-2">
      <span class="label">Pasang record DNS ini di penyedia domain pelanggan</span>
      <div class="overflow-x-auto rounded-md border border-line">
        <table class="w-full text-sm">
          <thead><tr class="text-left text-muted"><th class="px-3 py-2 font-medium">Tipe</th><th class="px-3 py-2 font-medium">Nama</th><th class="px-3 py-2 font-medium">Nilai</th><th></th></tr></thead>
          <tbody>
            <tr v-for="r in domain.dns" :key="r.type" class="border-t border-line">
              <td class="px-3 py-2 font-mono">{{ r.type }}</td>
              <td class="px-3 py-2 font-mono break-all">{{ r.name }}</td>
              <td class="px-3 py-2 font-mono break-all">{{ r.value }}</td>
              <td class="px-2 py-1 text-right"><AppButton variant="ghost" :aria-label="`Salin nilai ${r.type}`" @click="copy(r.value)"><Copy class="size-4" /></AppButton></td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="domain.last_error" class="text-sm text-danger" role="status">{{ domain.last_error }} <span class="text-muted">· dicek {{ dateTime(domain.last_checked_at) }}</span></p>
    </div>
    <p v-else class="text-sm text-muted">Terverifikasi {{ dateTime(domain.verified_at) }}. Dashboard workspace bisa dibuka di domain ini.</p>

    <div v-if="auth.can('tenants.manage')" class="flex flex-wrap gap-2">
      <AppButton v-if="domain.status === 'pending'" :loading="busy" @click="verify"><RefreshCw class="size-4" /> Cek DNS sekarang</AppButton>
      <AppButton v-if="domain.status === 'pending' && auth.can('system.manage')" variant="secondary" :loading="busy" @click="setStatus('active')">Aktifkan tanpa verifikasi</AppButton>
      <AppButton v-if="domain.status === 'active'" variant="secondary" :loading="busy" @click="setStatus('disabled')">Nonaktifkan</AppButton>
      <AppButton v-if="domain.status === 'disabled'" variant="secondary" :loading="busy" @click="setStatus('active')">Aktifkan lagi</AppButton>
      <AppButton variant="ghost" class="ml-auto" @click="confirming = true"><Trash2 class="size-4" /> Hapus</AppButton>
    </div>

    <AppModal v-if="confirming" size="sm" title="Hapus domain?" :description="`${domain.domain} langsung berhenti mengarah ke workspace ini.`" @close="confirming = false">
      <template #footer>
        <AppButton variant="secondary" @click="confirming = false">Batal</AppButton>
        <AppButton variant="danger" :loading="busy" @click="remove">Hapus domain</AppButton>
      </template>
    </AppModal>
  </article>
</template>
