<script setup>
// Form tambah domain kustom untuk satu workspace (ID workspace bisa diisi tetap dari halaman detail).
import { ref } from 'vue'
import { Plus } from 'lucide-vue-next'
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
  <!-- label di atas, input & tombol satu baris (rata atas) — petunjuk/galat di bawah baris agar tidak menggeser tombol -->
  <form class="grid gap-2" @submit.prevent="add">
    <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
      <AppField v-if="!props.tenant" label="ID workspace"><AppInput v-model="tenantId" code maxlength="26" required placeholder="26 karakter, salin dari detail workspace" :invalid="!!errors.tenant" /></AppField>
      <AppField label="Domain" :class="props.tenant ? 'sm:col-span-2' : ''">
        <AppInput v-model="domain" code maxlength="190" required placeholder="kartu.perusahaan.com" :invalid="!!errors.domain" />
      </AppField>
      <AppButton type="submit" :loading="busy"><Plus class="size-5" /> Tambah domain</AppButton>
    </div>
    <p v-if="errors.tenant || errors.domain" class="text-sm text-danger" role="alert">{{ errors.tenant?.[0] ?? errors.domain?.[0] }}</p>
    <p v-else class="text-sm text-muted">Tulis nama domain saja tanpa http://, mis. <span class="font-mono">kartu.perusahaan.com</span>. Setelah ditambahkan, kirim record DNS di bawah ke pelanggan.</p>
  </form>
</template>
