<script setup>
// Dialog "perubahan belum disimpan" untuk editor (dipakai bersama useLeaveGuard).
// Pilihan disusun dari yang paling aman: simpan lalu keluar (bila `canSave`), tetap di halaman, keluar tanpa menyimpan.
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
  <AppModal size="sm" label="Perubahan belum disimpan" @close="$emit('stay')">
    <div class="grid justify-items-center gap-3 text-center">
      <span class="grid size-14 place-items-center rounded-full bg-surface-2" aria-hidden="true"><TriangleAlert class="size-7 text-warn" /></span>
      <h2 class="text-xl leading-tight">Perubahan belum disimpan</h2>
      <p class="max-w-[320px] text-muted">Ada {{ what }} yang belum Anda simpan. Kalau keluar sekarang, perubahan itu akan hilang.</p>
    </div>
    <div class="grid gap-2 pt-2">
      <AppButton v-if="canSave" block size="lg" :loading="saving" @click="$emit('save')">Simpan &amp; keluar</AppButton>
      <AppButton block variant="secondary" :disabled="saving" @click="$emit('stay')">Tetap di sini</AppButton>
      <AppButton block variant="ghost" class="!text-danger" :disabled="saving" @click="$emit('leave')">Keluar tanpa menyimpan</AppButton>
    </div>
  </AppModal>
</template>
