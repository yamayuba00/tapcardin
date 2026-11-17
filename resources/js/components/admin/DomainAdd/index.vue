<script setup>
// Form tambah domain kustom: pilih workspace (atau terkunci dari halaman detail), isi domain.
import { onMounted, ref } from 'vue'
import { Plus } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
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
const options = ref([])

onMounted(async () => {
  if (props.tenant) return
  const res = await adminApi.tenants({ per_page: 100, sort: 'name' })
  options.value = (res.data ?? []).map((t) => ({ value: t.id, label: t.name, description: t.slug }))
})

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
  <form class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end" @submit.prevent="add">
    <AppField v-if="!props.tenant" label="Workspace" :error="errors.tenant?.[0]">
      <AppSelect v-model="tenantId" :options="options" searchable placeholder="Pilih workspace" :invalid="!!errors.tenant" />
    </AppField>
    <AppField label="Domain" :error="errors.domain?.[0]" :class="props.tenant ? 'sm:col-span-2' : ''">
      <AppInput v-model="domain" code maxlength="190" required placeholder="kartu.perusahaan.com" :invalid="!!errors.domain" />
    </AppField>
    <AppButton type="submit" :loading="busy" :disabled="!tenantId"><Plus class="size-5" /> Tambah</AppButton>
  </form>
</template>
