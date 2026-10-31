<script setup>
// Paket & harga — harga bulanan/tahunan, batas, fitur, tampil di halaman harga. Harga baru berlaku untuk tagihan
// berikutnya; batas & fitur langsung berlaku untuk semua workspace di paket itu.
import { computed, reactive, ref } from 'vue'
import { Pencil, Plus } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import AppToggle from '@/components/ui/AppToggle/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { useAction, useAsync } from '@/composables/useAsync'
import { num, rupiah } from '@/lib/format'

const { data: plans, meta, reload } = useAsync(() => adminApi.plans())
const { busy, run } = useAction()
const type = ref('personal')
const shown = computed(() => (plans.value ?? []).filter((p) => p.tenant_type === type.value))
const editing = ref(null) // null | 'new' | plan
const form = reactive({})
const errors = ref({})

function open(p) {
  errors.value = {}
  const limits = meta.value?.limits ?? {}
  const features = meta.value?.features ?? {}
  Object.assign(form, p
    ? { ...p, limits: { ...p.limits }, features: { ...p.features } }
    : {
        key: '', name: '', tenant_type: type.value, price_monthly: 0, price_yearly: 0, per_seat: type.value === 'company', trial_days: 0, is_public: false, sort: 99,
        limits: Object.fromEntries(Object.keys(limits).map((k) => [k, null])), features: Object.fromEntries(Object.keys(features).map((k) => [k, false])),
      })
  for (const k of Object.keys(form.limits)) form.limits[k] = form.limits[k] ?? ''
  editing.value = p ?? 'new'
}
const intOrNull = (v) => (v === '' || v == null ? null : Number(v))
function suggestYearly() {
  form.price_yearly = Number(form.price_monthly || 0) * 10
}
async function save() {
  errors.value = {}
  const body = {
    name: form.name, price_monthly: Number(form.price_monthly), price_yearly: Number(form.price_yearly), per_seat: form.per_seat,
    trial_days: Number(form.trial_days), is_public: form.is_public, sort: Number(form.sort),
    limits: Object.fromEntries(Object.entries(form.limits).map(([k, v]) => [k, intOrNull(v)])), features: form.features,
  }
  try {
    if (editing.value === 'new') await run(() => adminApi.createPlan({ ...body, key: form.key, tenant_type: form.tenant_type }), 'Paket dibuat.')
    else await run(() => adminApi.updatePlan(editing.value.key, body), 'Paket disimpan. Batas & fitur langsung berlaku.')
    editing.value = null
    reload()
  } catch (e) { errors.value = e.fields ?? {} }
}
const limitText = (v) => (v === null ? 'Tanpa batas' : num(v))
</script>

<template>
  <PageHeader eyebrow="Bisnis" title="Paket & harga">
    <AppButton @click="open(null)"><Plus class="size-5" /> Paket baru</AppButton>
  </PageHeader>
  <AppSegmented v-model="type" label="Tipe workspace" :options="[{ value: 'personal', label: 'Personal' }, { value: 'company', label: 'Perusahaan' }]" />

  <AppSkeleton v-if="!plans" class="!h-60" />
  <div v-else class="grid grid-cols-[repeat(auto-fill,minmax(280px,1fr))] gap-4">
    <AppPanel v-for="p in shown" :key="p.key">
      <div class="grid gap-4">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-semibold">{{ p.name }}</h2>
            <span class="font-mono text-xs text-muted">{{ p.key }}</span>
          </div>
          <AppBadge :tone="p.is_public ? 'ok' : 'muted'">{{ p.is_public ? 'Publik' : 'Tersembunyi' }}</AppBadge>
        </div>
        <div>
          <span class="font-display text-2xl">{{ p.price_monthly ? rupiah(p.price_monthly) : 'Gratis' }}</span>
          <span v-if="p.price_monthly" class="text-muted"> /bulan{{ p.per_seat ? ' /anggota' : '' }}</span>
          <span v-if="p.price_yearly" class="block text-sm text-muted">{{ rupiah(p.price_yearly) }} /tahun</span>
        </div>
        <dl class="grid grid-cols-[1fr_max-content] gap-x-4 gap-y-1 text-sm">
          <template v-for="(label, k) in meta?.limits" :key="k">
            <template v-if="k in p.limits && (p.limits[k] !== null || ['members', 'cards_active', 'connections'].includes(k))">
              <dt class="text-muted">{{ label }}</dt><dd class="font-mono">{{ limitText(p.limits[k]) }}</dd>
            </template>
          </template>
        </dl>
        <div class="flex flex-wrap gap-1.5">
          <AppBadge v-for="(label, k) in meta?.features" v-show="p.features[k]" :key="k" tone="outline" :dot="false">{{ label }}</AppBadge>
        </div>
        <div class="flex items-center justify-between gap-3 border-t border-line pt-3 text-sm text-muted">
          <span>{{ num(p.tenants_count) }} workspace · {{ num(p.subscriptions_count) }} langganan</span>
          <AppButton variant="ghost" @click="open(p)"><Pencil class="size-4" /> Ubah</AppButton>
        </div>
      </div>
    </AppPanel>
  </div>

  <AppModal v-if="editing" size="lg" :title="editing === 'new' ? 'Paket baru' : `Ubah paket ${editing.name}`"
    description="Harga baru dipakai untuk checkout & perpanjangan berikutnya. Batas dan fitur langsung berlaku." @close="editing = null">
    <form id="plan-form" class="grid gap-6" @submit.prevent="save">
      <div class="grid gap-4 sm:grid-cols-3">
        <AppField v-if="editing === 'new'" label="Kunci (unik)" hint="huruf kecil & _" :error="errors.key?.[0]"><AppInput v-model="form.key" code maxlength="32" required /></AppField>
        <AppField label="Nama" :error="errors.name?.[0]"><AppInput v-model="form.name" maxlength="60" required /></AppField>
        <AppField label="Urutan"><AppInput v-model="form.sort" type="number" min="0" /></AppField>
      </div>
      <div class="grid gap-4 sm:grid-cols-3">
        <AppField label="Harga bulanan (Rp)" :error="errors.price_monthly?.[0]"><AppInput v-model="form.price_monthly" type="number" min="0" @change="!form.price_yearly && suggestYearly()" /></AppField>
        <AppField label="Harga tahunan (Rp)" :error="errors.price_yearly?.[0]">
          <div class="flex gap-2"><AppInput v-model="form.price_yearly" type="number" min="0" /><AppButton type="button" variant="secondary" title="10 × bulanan (hemat 2 bulan)" @click="suggestYearly">×10</AppButton></div>
        </AppField>
        <AppField label="Masa uji coba (hari)"><AppInput v-model="form.trial_days" type="number" min="0" max="90" /></AppField>
      </div>
      <div class="flex flex-wrap gap-6">
        <AppToggle v-model="form.is_public" label="Tampil di halaman harga & bisa dibeli" />
        <AppToggle v-model="form.per_seat" label="Harga per anggota" />
      </div>

      <fieldset class="grid gap-3">
        <legend class="label mb-2">Batas (kosong = tanpa batas)</legend>
        <div class="grid gap-4 sm:grid-cols-3">
          <AppField v-for="(label, k) in meta?.limits" :key="k" :label="label" :error="errors[`limits.${k}`]?.[0]">
            <AppInput v-model="form.limits[k]" type="number" min="0" placeholder="∞" />
          </AppField>
        </div>
      </fieldset>

      <fieldset class="grid gap-3">
        <legend class="label mb-2">Fitur</legend>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <AppToggle v-for="(label, k) in meta?.features" :key="k" v-model="form.features[k]" :label="label" />
        </div>
      </fieldset>
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="editing = null">Batal</AppButton>
      <AppButton type="submit" form="plan-form" :loading="busy">Simpan paket</AppButton>
    </template>
  </AppModal>
</template>
