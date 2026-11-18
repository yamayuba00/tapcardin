<script setup>
// Tombol "Simpan ke Kontak HP". iOS: vCard langsung (layar "Kontak Baru" bawaan). Android & desktop: lembar petunjuk
// dulu, karena web hanya bisa memberi file .vcf — tanpa penjelasan unduhan mendadak terasa mencurigakan.
import { computed, ref } from 'vue'
import { Copy, Download, ExternalLink, UserPlus } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import { contactDevice, savesDirectly } from '@/lib/saveContact'

defineOptions({ inheritAttrs: false })
const props = defineProps({
  href: { type: String, default: null },
  name: { type: String, required: true },
  preview: Boolean,
  theme: { type: String, default: null },
  vars: { type: Object, default: null },
})

const device = contactDevice(typeof navigator === 'undefined' ? '' : navigator.userAgent)
const open = ref(false)
const copied = ref(false)
const steps = computed(() => (device.platform === 'android'
  ? ['Ketuk Simpan di bawah.', 'Ketuk "Buka" pada pemberitahuan di bawah layar.', 'Pilih Kontak, lalu Simpan.']
  : ['Ketuk Simpan untuk mengambil kartu kontak.', 'Buka file itu dengan aplikasi Kontak, atau pindai QR kartu dari HP.']))

function onClick(e) {
  if (props.preview || !props.href || savesDirectly(device)) return
  e.preventDefault()
  copied.value = false
  open.value = true
}

// tutup setelah klik selesai agar unduhan tautan tetap berjalan
const closeSoon = () => setTimeout(() => (open.value = false), 0)

async function copyLink() {
  try {
    await navigator.clipboard.writeText(window.location.href)
    copied.value = true
  } catch { /* izin clipboard ditolak: tautan tetap terlihat di bilah alamat */ }
}
</script>

<template>
  <a :href="href || undefined" v-bind="$attrs" :aria-disabled="preview || undefined" @click="onClick"><slot><UserPlus class="size-5" /> Simpan ke Kontak HP</slot></a>

  <AppModal v-if="open" sheet :title="`Simpan ${name} ke Kontak`" :theme="theme" :vars="vars" @close="open = false">
    <p class="text-sm text-muted">Ini kartu kontak standar (.vcf) yang dibuka oleh aplikasi Kontak di HP Anda. Aman, tanpa perlu memasang aplikasi.</p>
    <div v-if="device.inApp" class="grid gap-3 rounded-md border border-warn bg-surface-2 p-3 text-sm" role="note">
      <p class="flex items-start gap-2"><ExternalLink class="mt-0.5 size-4 flex-none text-warn" aria-hidden="true" /> Buka halaman ini di Chrome/Safari agar bisa simpan kontak.</p>
      <AppButton variant="secondary" @click="copyLink"><Copy class="size-4" /> {{ copied ? 'Tautan disalin' : 'Salin tautan' }}</AppButton>
    </div>
    <ol class="grid list-decimal gap-1.5 pl-5 text-sm">
      <li v-for="s in steps" :key="s">{{ s }}</li>
    </ol>
    <template #footer>
      <AppButton variant="secondary" @click="open = false">Batal</AppButton>
      <AppButton :href="href" @click="closeSoon"><Download class="size-5" /> Simpan</AppButton>
    </template>
  </AppModal>
</template>
