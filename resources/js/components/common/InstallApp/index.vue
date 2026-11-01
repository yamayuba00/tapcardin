<script setup>
// Ajakan pasang aplikasi (PWA). Android/desktop: dialog pemasangan bawaan browser. iOS: petunjuk langkah manual.
// `variant="nav"` = tautan di sidebar; `variant="banner"` = kartu ajakan (Beranda), bisa ditutup.
import { ref } from 'vue'
import { Download, Share, SquarePlus, X } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import { usePwa } from '@/composables/usePwa'
import { useToastStore } from '@/stores/toast'

defineProps({ variant: { type: String, default: 'nav' }, linkClass: { type: String, default: '' } })
const { available, canPrompt, dismissed, install, dismiss } = usePwa()
const toast = useToastStore()
const guide = ref(false)
const ICON = '/icons/icon-192.png' // aset public/, bukan modul Vite

async function go() {
  if (!canPrompt.value) {
    guide.value = true
    return
  }
  if (await install()) toast.ok('TapCard terpasang di layar utama.')
}
</script>

<template>
  <template v-if="available">
    <button v-if="variant === 'nav'" type="button" class="cursor-pointer" :class="linkClass" @click="go">
      <Download class="size-5" aria-hidden="true" /> Pasang aplikasi
    </button>

    <!-- Ponsel: ikon + teks di atas, tombol Pasang selebar kartu di bawah, × di pojok kanan atas.
         Desktop (sm+): satu baris — ikon, teks, tombol, ×. -->
    <div v-else-if="!dismissed" class="relative grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-4 gap-y-3 rounded-md border border-line-strong bg-surface-2 p-4 pr-12 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:px-5 sm:py-4 sm:pr-16">
      <img :src="ICON" alt="" width="44" height="44" class="size-11 flex-none rounded-lg" />
      <div class="grid min-w-0 gap-0.5">
        <span class="font-semibold">Pasang TapCard di layar utama</span>
        <span class="text-sm text-muted">Buka QR & tautan kartu dalam satu ketukan, tanpa membuka browser dulu.</span>
      </div>
      <AppButton class="col-span-2 sm:col-span-1" @click="go"><Download class="size-5" /> Pasang</AppButton>
      <AppButton variant="ghost" icon class="absolute top-1 right-1 sm:top-1/2 sm:right-3 sm:-translate-y-1/2" aria-label="Tutup ajakan pasang aplikasi" @click="dismiss"><X class="size-5" /></AppButton>
    </div>
  </template>

  <AppModal v-if="guide" size="sm" title="Pasang di iPhone / iPad" description="Safari tidak punya tombol pasang otomatis — cukup tiga langkah ini." @close="guide = false">
    <ol class="grid gap-4 text-sm">
      <li class="flex items-start gap-3"><span class="num grid size-7 flex-none place-items-center rounded-full bg-surface-2">1</span><span>Ketuk tombol <b>Bagikan</b> <Share class="inline size-4 align-text-bottom" aria-label="ikon bagikan" /> di bilah bawah Safari.</span></li>
      <li class="flex items-start gap-3"><span class="num grid size-7 flex-none place-items-center rounded-full bg-surface-2">2</span><span>Pilih <b>Tambahkan ke Layar Utama</b> <SquarePlus class="inline size-4 align-text-bottom" aria-label="ikon tambah" />.</span></li>
      <li class="flex items-start gap-3"><span class="num grid size-7 flex-none place-items-center rounded-full bg-surface-2">3</span><span>Ketuk <b>Tambah</b>. Ikon TapCard muncul di layar utama.</span></li>
    </ol>
    <template #footer><AppButton @click="guide = false">Mengerti</AppButton></template>
  </AppModal>
</template>
