<script setup>
import { ref, computed, watch } from 'vue'
import { Plus, ChevronDown } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import { adminApi } from '@/api/admin'
import { useAction } from '@/composables/useAsync'
import { useToastStore } from '@/stores/toast'

const props = defineProps({ tenant: { type: String, default: '' } })
const emit = defineEmits(['added'])
const toast = useToastStore()
const { busy, run } = useAction()
const tenantId = ref(props.tenant)
const domain = ref('')
const errors = ref({})
const tenants = ref([])
const tenantSearch = ref('')
const showTenantList = ref(false)
const { busy: loadingTenants } = useAction()

const filteredTenants = computed(() =>
  tenants.value.filter(t =>
    tenantSearch.value === '' ||
    t.name.toLowerCase().includes(tenantSearch.value.toLowerCase()) ||
    t.slug?.toLowerCase().includes(tenantSearch.value.toLowerCase())
  )
)

const selectedTenant = computed(() =>
  tenants.value.find(t => t.id === tenantId.value)
)

watch(tenantSearch, () => { showTenantList.value = true })

async function loadTenants() {
  if (tenants.value.length > 0) return
  try {
    const res = await adminApi.tenants({ perPage: 500 })
    tenants.value = res.data || []
  } catch (e) {
    toast.error('Gagal memuat workspace')
  }
}

function selectTenant(t) {
  tenantId.value = t.id
  tenantSearch.value = t.name
  showTenantList.value = false
}

async function add() {
  errors.value = {}
  try {
    const res = await run(() => adminApi.addDomain(tenantId.value, domain.value))
    if (res.data.plan_allows === false) toast.error('Domain ditambahkan, tetapi paket workspace ini belum mencakup "Domain kustom".')
    else toast.ok('Domain ditambahkan — kirim record DNS ke pelanggan.')
    domain.value = ''
    emit('added', res.data)
  } catch (e) { errors.value = e.fields ?? {} }
}
</script>

<template>
  <form class="grid gap-2" @submit.prevent="add">
    <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
      <AppField v-if="!props.tenant" label="Workspace">
        <div class="relative">
          <div class="flex gap-2 items-center border border-line rounded bg-surface px-3 py-2 text-sm">
            <input
              v-model="tenantSearch"
              @focus="loadTenants(); showTenantList = true"
              @blur="setTimeout(() => { showTenantList = false }, 150)"
              placeholder="Cari workspace..."
              class="flex-1 bg-transparent outline-none"
              required
            />
            <ChevronDown class="size-4 text-muted flex-none" />
          </div>
          <div v-if="showTenantList && tenants.length > 0" class="absolute top-full left-0 right-0 mt-1 bg-surface border border-line rounded z-10 max-h-48 overflow-y-auto">
            <button
              v-for="t in filteredTenants"
              :key="t.id"
              type="button"
              @click="selectTenant(t)"
              class="w-full text-left px-3 py-2 hover:bg-surface-2 text-sm"
            >
              <div class="font-medium">{{ t.name }}</div>
              <div class="text-xs text-muted">{{ t.slug }}</div>
            </button>
            <div v-if="filteredTenants.length === 0" class="px-3 py-2 text-sm text-muted">Tidak ada hasil</div>
          </div>
        </div>
        <p v-if="errors.tenant" class="text-xs text-danger mt-1">{{ errors.tenant[0] }}</p>
      </AppField>
      <AppField label="Domain" :class="props.tenant ? 'sm:col-span-2' : ''">
        <AppInput v-model="domain" code maxlength="190" required placeholder="kartu.perusahaan.com" :invalid="!!errors.domain" />
        <p class="text-xs text-muted mt-1">
          <span v-if="selectedTenant" class="block">Contoh: <span class="font-mono">{{ selectedTenant.slug }}.localhost</span> atau <span class="font-mono">kartu.{{ selectedTenant.name.toLowerCase().replace(/\s+/g, '') }}.com</span></span>
        </p>
      </AppField>
      <AppButton type="submit" :loading="busy"><Plus class="size-5" /> Tambah</AppButton>
    </div>
    <p v-if="errors.domain" class="text-sm text-danger" role="alert">{{ errors.domain[0] }}</p>
    <p v-else class="text-sm text-muted">Tanpa http://, mis. <span class="font-mono">kartu.perusahaan.com</span>. Verifikasi DNS setelah ditambahkan.</p>
  </form>
</template>
