<script setup>
// Konfirmasi keluar — dipasang sekali di App; semua tombol "Keluar" memanggil auth.askLogout().
import { ref } from 'vue'
import { LogOut } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import { context } from '@/config'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const busy = ref(false)
async function confirm() {
  busy.value = true
  await auth.logout() // pindah ke /login
}
</script>

<template>
  <AppModal v-if="auth.confirmingLogout" size="sm" label="Keluar dari akun" :dismissible="!busy" @close="auth.confirmingLogout = false">
    <div class="grid justify-items-center gap-3 text-center">
      <span class="grid size-14 place-items-center rounded-full bg-surface-2" aria-hidden="true"><LogOut class="size-7 text-danger" /></span>
      <h2 class="text-xl leading-tight">Keluar dari {{ context === 'admin' ? 'panel' : 'TapCard' }}?</h2>
      <p class="max-w-[320px] text-muted">
        {{ context === 'admin' ? 'Anda perlu masuk lagi dengan email & password untuk membuka panel.' : 'Anda perlu masuk lagi dengan Google atau kartu NFC untuk membuka akun ini.' }}
      </p>
    </div>
    <div class="grid gap-2 pt-2">
      <AppButton variant="danger" block size="lg" :loading="busy" @click="confirm">Ya, keluar</AppButton>
      <AppButton variant="secondary" block :disabled="busy" @click="auth.confirmingLogout = false">Batal</AppButton>
    </div>
  </AppModal>
</template>
