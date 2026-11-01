<script setup>
// PL-02 detail — paket, batas khusus, catatan internal, suspend/aktifkan (alasan wajib), langganan & invoice,
// beri paket, domain kustom, anggota, kartu.
import { reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { Copy, Gift } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppMeter from '@/components/ui/AppMeter/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import CardsTable from '@/components/admin/CardsTable/index.vue'
import DomainAdd from '@/components/admin/DomainAdd/index.vue'
import DomainCard from '@/components/admin/DomainCard/index.vue'
import DataTable from '@/components/ui/DataTable/index.vue'
import AppTextarea from '@/components/ui/AppTextarea/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { useAction, useAsync } from '@/composables/useAsync'
import { usePagedList } from '@/composables/usePagedList'
import { date, dateTime, rupiah } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'

const route = useRoute()
const auth = useAuthStore()
const id = route.params.id
const { data: t } = useAsync(() => adminApi.tenant(id))
const members = usePagedList((p) => adminApi.tenantMembers(id, p), {}, { url: false, sort: 'joined_at', perPage: 10 })
const cards = usePagedList((p) => adminApi.tenantCards(id, p), {}, { url: false, sort: '-updated_at', perPage: 10 })
const MEMBER_COLUMNS = [{ key: 'name', label: 'Anggota', sortable: true }, { key: 'role', label: 'Peran' }, { key: 'status', label: 'Status', sortable: true }]
const { busy, run } = useAction()
const form = reactive({ plan_id: null, internal_note: '', limit_cards_active: '', limit_connections: '' })
const reason = ref('')
const errors = ref({})

watch(t, (v) => v && Object.assign(form, {
  plan_id: v.plan_id, internal_note: v.internal_note || '',
  limit_cards_active: v.limit_overrides.cards_active ?? '', limit_connections: v.limit_overrides.connections ?? '',
}), { immediate: true })

const nullable = (v) => (v === '' || v == null ? null : Number(v))
async function save() {
  const res = await run(() => adminApi.updateTenant(id, {
    ...form, limit_cards_active: nullable(form.limit_cards_active), limit_connections: nullable(form.limit_connections),
  }), 'Workspace diperbarui.').catch(() => null)
  if (res) t.value = res.data
}
async function suspend() {
  errors.value = {}
  try {
    t.value = (await run(() => adminApi.suspendTenant(id, reason.value), 'Workspace disuspend. Kartu tampil netral.')).data
    reason.value = ''
  } catch (e) { errors.value = e.fields }
}
async function reactivate() {
  const res = await run(() => adminApi.reactivateTenant(id), 'Workspace diaktifkan kembali.').catch(() => null)
  if (res) t.value = res.data
}
const USAGE = { members: 'Anggota', cards_active: 'Kartu aktif', connections: 'Koneksi/lead' }
const INV = { issued: ['warn', 'Belum dibayar'], overdue: ['danger', 'Terlambat'], paid: ['ok', 'Lunas'], void: ['muted', 'Dibatalkan'], draft: ['muted', 'Draf'] }
const SUB = { active: ['ok', 'Aktif'], trialing: ['info', 'Uji coba'], past_due: ['danger', 'Menunggak'], cancelled: ['muted', 'Berhenti'], expired: ['muted', 'Berakhir'] }
const toast = useToastStore()
async function copyId() {
  try { await navigator.clipboard.writeText(id); toast.ok('ID workspace disalin.') } catch { toast.error('Tidak bisa menyalin.') }
}

// domain kustom (disimpan di t.domains agar langsung ter-update)
const domainAdded = (d) => { t.value.domains = [...t.value.domains, d] }
const domainUpdated = (d) => { t.value.domains = t.value.domains.map((x) => (x.id === d.id ? d : x)) }
const domainRemoved = (did) => { t.value.domains = t.value.domains.filter((x) => x.id !== did) }

// beri paket tanpa pembayaran
const granting = ref(false)
const grant = reactive({ plan: '', months: 1, reason: '', reference: '' })
const grantErrors = ref({})
function openGrant() {
  grant.plan = t.value.plans.find((p) => p.id === t.value.plan_id)?.key ?? t.value.plans[0]?.key
  granting.value = true
}
async function doGrant() {
  grantErrors.value = {}
  try {
    await run(() => adminApi.grantPlan({ tenant: id, plan: grant.plan, months: Number(grant.months), reason: grant.reason, reference: grant.reference }), 'Permintaan dikirim — menunggu persetujuan staf lain.')
    granting.value = false
    grant.reason = ''
    grant.reference = ''
  } catch (e) { grantErrors.value = e.fields ?? {} }
}
</script>

<template>
  <AppSkeleton v-if="!t" class="!h-60" />
  <template v-else>
    <PageHeader :title="t.name" back="/tenants" back-label="Workspace">
      <template #meta>
        <div class="flex flex-wrap gap-2">
          <AppBadge :tone="t.status === 'suspended' ? 'danger' : 'ok'">{{ t.status }}</AppBadge>
          <AppBadge tone="outline">{{ t.type === 'personal' ? 'Personal' : 'Perusahaan' }}</AppBadge>
          <AppBadge tone="outline">{{ t.plan }}</AppBadge>
        </div>
      </template>
    </PageHeader>

    <div class="grid gap-4 md:grid-cols-2">
      <AppPanel title="Ringkasan">
        <div class="grid gap-4">
          <dl class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
            <dt class="text-muted">ID</dt>
            <dd class="flex items-center gap-1 font-mono text-xs">{{ t.id }}<button type="button" class="cursor-pointer rounded p-1 text-muted hover:text-fg" aria-label="Salin ID workspace" @click="copyId"><Copy class="size-4" /></button></dd>
            <dt class="text-muted">Owner</dt><dd><RouterLink v-if="t.owner" :to="`/users/${t.owner.id}`">{{ t.owner.email }}</RouterLink></dd>
            <dt class="text-muted">Subdomain</dt><dd class="font-mono">{{ t.slug ?? '— (app.)' }}</dd>
            <dt class="text-muted">Dibuat</dt><dd>{{ dateTime(t.created_at) }}</dd>
            <template v-if="t.suspend_reason"><dt class="text-muted">Alasan suspend</dt><dd>{{ t.suspend_reason }}</dd></template>
          </dl>
          <h3>Pemakaian vs batas</h3>
          <AppMeter v-for="(label, k) in USAGE" :key="k" :label="label" :value="t.usage[k]" :max="t.limits[k] ?? null" />
        </div>
      </AppPanel>

      <AppPanel title="Paket & batas khusus">
        <div class="grid gap-4">
          <template v-if="auth.can('tenants.manage')">
            <AppField label="Paket"><AppSelect v-model="form.plan_id" :options="t.plans.map((p) => ({ value: p.id, label: p.name }))" /></AppField>
            <div class="grid gap-4 sm:grid-cols-2">
              <AppField label="Batas kartu aktif" hint="Kosong = ikut paket"><AppInput v-model="form.limit_cards_active" type="number" min="0" /></AppField>
              <AppField label="Batas koneksi"><AppInput v-model="form.limit_connections" type="number" min="0" /></AppField>
            </div>
            <AppField label="Catatan internal (tidak terlihat pelanggan)"><AppTextarea v-model="form.internal_note" /></AppField>
            <div><AppButton :loading="busy" @click="save">Simpan</AppButton></div>
          </template>
          <template v-if="auth.can('tenants.suspend')">
            <hr class="border-line" />
            <AppButton v-if="t.status === 'suspended'" variant="secondary" :loading="busy" @click="reactivate">Aktifkan kembali</AppButton>
            <form v-else class="grid gap-2" @submit.prevent="suspend">
              <AppField label="Alasan suspend (wajib)" :error="errors.reason?.[0]"><AppInput v-model="reason" maxlength="200" /></AppField>
              <p class="text-sm text-muted">Dampak: dashboard terkunci, semua kartu tampil netral. Data tidak dihapus.</p>
              <div><AppButton type="submit" variant="danger" :loading="busy">Suspend workspace</AppButton></div>
            </form>
          </template>
        </div>
      </AppPanel>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
      <AppPanel title="Langganan">
        <template v-if="auth.can('finance.invoices')" #actions><AppButton variant="ghost" @click="openGrant"><Gift class="size-4" /> Ajukan beri paket</AppButton></template>
        <dl v-if="t.subscription" class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
          <dt class="text-muted">Paket</dt><dd>{{ t.subscription.plan }} · {{ t.subscription.cycle === 'yearly' ? 'tahunan' : 'bulanan' }}</dd>
          <dt class="text-muted">Status</dt>
          <dd><AppBadge :tone="SUB[t.subscription.status]?.[0]">{{ SUB[t.subscription.status]?.[1] }}</AppBadge><span v-if="t.subscription.cancel_at_period_end" class="ml-2 text-muted">berhenti di akhir periode</span></dd>
          <dt class="text-muted">Berakhir</dt><dd>{{ date(t.subscription.current_period_end) }}</dd>
          <template v-if="t.subscription.grace_until"><dt class="text-muted">Tenggang</dt><dd class="text-danger">s/d {{ date(t.subscription.grace_until) }}</dd></template>
        </dl>
        <p v-else class="text-sm text-muted">Belum pernah berlangganan — memakai paket {{ t.plan }}.</p>
      </AppPanel>

      <AppPanel v-if="auth.can('finance.view')" title="Invoice terbaru" flush>
        <template #actions><AppButton variant="ghost" :to="`/finance?tab=invoices`">Keuangan</AppButton></template>
        <ul>
          <li v-for="i in t.invoices" :key="i.id" class="flex items-center gap-3 border-t border-line px-5 py-3 text-sm first:border-t-0">
            <RouterLink :to="`/finance/invoices/${i.id}`" class="font-mono">{{ i.number }}</RouterLink>
            <AppBadge :tone="INV[i.status]?.[0]">{{ INV[i.status]?.[1] }}</AppBadge>
            <span class="ml-auto font-mono">{{ rupiah(i.total) }}</span>
          </li>
          <li v-if="!t.invoices.length" class="px-5 py-4 text-sm text-muted">Belum ada invoice.</li>
        </ul>
      </AppPanel>
    </div>

    <AppPanel title="Domain kustom">
      <div class="grid gap-4">
        <p v-if="!t.features?.custom_domain" class="text-sm text-muted">Paket {{ t.plan }} belum mencakup Domain kustom — domain tetap bisa disiapkan, tetapi pertimbangkan upgrade paket.</p>
        <DomainCard v-for="d in t.domains" :key="d.id" :domain="d" @update="domainUpdated" @remove="domainRemoved" />
        <DomainAdd v-if="auth.can('tenants.manage')" :tenant="t.id" @added="domainAdded" />
      </div>
    </AppPanel>

    <DataTable
      dense row-key="user_id" :columns="MEMBER_COLUMNS" :rows="members.rows.value" :meta="members.meta.value" :sort="members.sort.value"
      :loading="members.loading.value" empty-text="Belum ada anggota." @sort="members.toggleSort" @page="members.goto" @per-page="members.setPerPage"
    >
      <template #cell-name="{ row: m }"><RouterLink :to="`/users/${m.user_id}`">{{ m.name }}</RouterLink> <span class="text-sm text-muted">{{ m.email }}</span></template>
    </DataTable>

    <CardsTable :list="cards" :show="['holder']" empty-text="Belum ada kartu." />

    <AppModal v-if="granting" size="sm" title="Ajukan pemberian paket" description="Kompensasi, mitra, atau uji coba khusus. Tidak langsung berjalan: staf lain harus menyetujui di menu Persetujuan." @close="granting = false">
      <form id="tenant-grant" class="grid gap-4" @submit.prevent="doGrant">
        <AppField label="Paket" :error="grantErrors.plan?.[0]"><AppSelect v-model="grant.plan" :options="t.plans.map((p) => ({ value: p.key, label: p.name }))" /></AppField>
        <AppField label="Durasi (bulan)" :error="grantErrors.months?.[0]"><AppInput v-model="grant.months" type="number" min="1" max="36" /></AppField>
        <AppField label="Alasan (wajib)" :error="grantErrors.reason?.[0]"><AppInput v-model="grant.reason" maxlength="200" minlength="5" required /></AppField>
        <AppField label="Nomor tiket / referensi (wajib)" :error="grantErrors.reference?.[0]"><AppInput v-model="grant.reference" maxlength="80" minlength="3" required /></AppField>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="granting = false">Batal</AppButton>
        <AppButton type="submit" form="tenant-grant" :loading="busy">Beri paket</AppButton>
      </template>
    </AppModal>
  </template>
</template>
