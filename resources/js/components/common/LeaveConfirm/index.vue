<script setup>
// Dialog "perubahan belum disimpan" untuk editor (dipakai bersama useLeaveGuard).
// Tiga pilihan: tetap di halaman, simpan lalu keluar (bila `canSave`), atau keluar tanpa menyimpan.
import { TriangleAlert } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'

defineProps({
  what: { type: String, default: 'perubahan' }, // mis. "perubahan bio link"
  canSave: Boolean,
  saving: Boolean,
})
defineEmits(['stay', 'leave', 'save'])
</script>

<template>
  <AppModal size="sm" title="Tinggalkan tanpa menyimpan?" @close="$emit('stay')">
    <div class="flex items-start gap-3 rounded-md border border-line bg-surface-2 p-4">
      <TriangleAlert class="mt-0.5 size-5 flex-none text-warn" aria-hidden="true" />
      <p class="text-sm">Ada {{ what }} yang belum disimpan. Jika keluar sekarang, perubahan itu akan hilang.</p>
    </div>
    <template #footer>
      <AppButton variant="ghost" class="sm:mr-auto" :disabled="saving" @click="$emit('leave')"><span class="text-danger">Keluar tanpa menyimpan</span></AppButton>
      <AppButton variant="secondary" :disabled="saving" @click="$emit('stay')">Tetap di sini</AppButton>
      <AppButton v-if="canSave" :loading="saving" @click="$emit('save')">Simpan &amp; keluar</AppButton>
    </template>
  </AppModal>
</template>
