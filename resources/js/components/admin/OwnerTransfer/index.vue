<script setup>
// Dialog ganti owner workspace perusahaan: pilih akun terdaftar; owner lama tetap anggota sebagai Admin.
import { ref } from 'vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import UserSelect from '@/components/admin/UserSelect/index.vue'
import { adminApi } from '@/api/admin'
import { useAction } from '@/composables/useAsync'

const props = defineProps({ tenant: { type: Object, required: true } })
const emit = defineEmits(['changed', 'close'])
const { busy, run } = useAction()
const user = ref('')
const error = ref('')

async function save() {
  error.value = ''
  try {
    const res = await run(() => adminApi.transferOwner(props.tenant.id, user.value), 'Owner diganti.')
    emit('changed', res.data)
    emit('close')
  } catch (e) { error.value = e.fields?.user?.[0] ?? e.message }
}
</script>

<template>
  <AppModal size="sm" title="Ganti owner" :description="`Owner baru mendapat semua akses ${tenant.name}. Owner lama tetap menjadi anggota dengan peran Admin.`" @close="emit('close')">
    <form id="owner-transfer" class="grid gap-4" @submit.prevent="save">
      <AppField label="Owner baru" :error="error">
        <UserSelect v-model="user" :exclude="tenant.owner?.id" :invalid="!!error" />
      </AppField>
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="emit('close')">Batal</AppButton>
      <AppButton type="submit" form="owner-transfer" :loading="busy" :disabled="!user">Ganti owner</AppButton>
    </template>
  </AppModal>
</template>
