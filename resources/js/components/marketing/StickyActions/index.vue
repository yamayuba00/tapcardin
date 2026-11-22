<script setup>
// Aksi menempel setelah hero terlewati: desktop = tombol "Hubungi Sales" (WhatsApp) mengambang kanan bawah;
// ponsel = bilah bawah berisi CTA utama + ikon WhatsApp (satu tempat, tidak saling menutupi).
// Tombol WhatsApp disembunyikan bila nomor sales belum diatur (TAPCARD_SALES_WHATSAPP).
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ArrowRight, MessageCircle } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import { config } from '@/config'
import { salesWhatsAppUrl } from '@/lib/salesWhatsApp'
import { track } from '@/lib/track'

// muncul setelah `after` (hero) terlewati, hilang lagi saat `until` (blok penutup yang punya tombol sendiri) terlihat
const props = defineProps({ signup: { type: String, required: true }, after: { type: String, default: 'hero' }, until: { type: String, default: 'penutup' } })
const wa = computed(() => salesWhatsAppUrl(config.salesWhatsapp, 'floating'))
const past = ref(false)
const ending = ref(false)
const shown = computed(() => past.value && !ending.value)
let io = null
onMounted(() => {
  const start = document.getElementById(props.after)
  const end = document.getElementById(props.until)
  if (!start || !('IntersectionObserver' in window)) return (past.value = true)
  io = new IntersectionObserver((entries) => {
    for (const e of entries) {
      if (e.target === start) past.value = !e.isIntersecting && e.boundingClientRect.top < 0
      else ending.value = e.isIntersecting
    }
  })
  io.observe(start)
  if (end) io.observe(end)
})
onBeforeUnmount(() => io?.disconnect())
</script>

<template>
  <!-- desktop: WhatsApp sales mengambang -->
  <a
    v-if="wa && shown" :href="wa" target="_blank" rel="noopener"
    class="fixed right-6 bottom-6 z-40 hidden min-h-12 items-center gap-2 rounded-full border border-line-strong bg-surface px-5 font-semibold text-fg no-underline hover:bg-surface-2 sm:inline-flex"
    data-event="whatsapp_click" data-location="floating" @click="track('whatsapp_click', { location: 'floating' })"
  ><MessageCircle class="size-5 text-ok" aria-hidden="true" /> Hubungi Sales</a>

  <!-- ponsel: bilah bawah -->
  <div v-if="shown" class="fixed inset-x-0 bottom-0 z-40 flex gap-2 border-t border-line bg-bg px-4 pt-3 pb-[max(.75rem,env(safe-area-inset-bottom))] sm:hidden">
    <AppButton block :href="signup" class="flex-1" data-event="cta_click" data-location="sticky" @click="track('cta_click', { location: 'sticky' })">
      Buat kartu gratis <ArrowRight class="size-5" />
    </AppButton>
    <a
      v-if="wa" :href="wa" target="_blank" rel="noopener" aria-label="Hubungi Sales lewat WhatsApp"
      class="grid size-11 flex-none place-items-center rounded-md border border-line-strong text-fg no-underline hover:bg-surface-2"
      data-event="whatsapp_click" data-location="sticky" @click="track('whatsapp_click', { location: 'sticky' })"
    ><MessageCircle class="size-5 text-ok" aria-hidden="true" /></a>
  </div>
</template>
