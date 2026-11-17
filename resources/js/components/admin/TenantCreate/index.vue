<script setup>
// Dialog buat workspace perusahaan: nama → subdomain (otomatis, bisa diubah) → email owner → paket.
import { computed, onMounted, ref, watch } from 'vue'
import { Plus } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import { adminApi } from '@/api/admin'
import { useAction } from '@/composables/useAsync'
import { useToastStore } from '@/stores/toast'

const emit = defineEmits(['created', 'close'])
const toast = useToastStore()
const { busy, run } = useAction()
const form = ref({ name: '', slug: '', owner_email: '', plan: '' })
const slugEdited = ref(false)
const errors = ref({})
const plans = ref([])

const slugify = (s) => s.toLowerCase().normalize('NFKD').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 30)
watch(() => form.value.name, (n) => { if (!slugEdited.value) form.value.slug = slugify(n) })

const options = computed(() => plans.value.map((p) => ({ value: p.key, label: p.name })))
onMounted(async () => {
  const res = await adminApi.plans()
  plans.value = (res.data ?? []).filter((p) => p.tenant_type === 'company')
  form.value.plan = plans.value.find((p) => p.key === 'business')?.key ?? plans.value[0]?.key ?? ''
})

async function create() {
  errors.value = {}
  try {
    const res = await run(() => adminApi.createTenant(form.value))
    toast.ok(`Workspace dibuat — ${res.data.url}`)
    emit('created', res.data)
    emit('close')
  } catch (e) { errors.value = e.fields ?? {} }
}
</script>

<template>
  <AppModal size="sm" title="Buat workspace perusahaan" description="Subdomain langsung aktif. Owner harus sudah pernah login ke TapCard." @close="emit('close')">
    <form id="tenant-create" class="grid gap-4" @submit.prevent="create">
      <AppField label="Nama perusahaan" :error="errors.name?.[0]">
        <AppInput v-model="form.name" maxlength="120" required placeholder="Maju Jaya" :invalid="!!errors.name" />
      </AppField>
      <AppField label="Subdomain" :error="errors.slug?.[0]">
        <AppInput v-model="form.slug" maxlength="30" required placeholder="majujaya" :invalid="!!errors.slug" @input="slugEdited = true" />
      </AppField>
      <AppField label="Email owner" :error="errors.owner_email?.[0]">
        <AppInput v-model="form.owner_email" type="email" required placeholder="owner@perusahaan.com" :invalid="!!errors.owner_email" />
      </AppField>
      <AppField label="Paket" :error="errors.plan?.[0]">
        <AppSelect v-model="form.plan" :options="options" placeholder="Pilih paket" :invalid="!!errors.plan" />
      </AppField>
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="emit('close')">Batal</AppButton>
      <AppButton type="submit" form="tenant-create" :loading="busy"><Plus class="size-5" /> Buat</AppButton>
    </template>
  </AppModal>
</template>
