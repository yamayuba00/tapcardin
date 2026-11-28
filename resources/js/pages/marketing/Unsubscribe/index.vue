<script setup>
// Berhenti berlangganan email promo (tautan bertanda tangan dari email blast). GET hanya menampilkan konfirmasi;
// tombol mengirim POST ke URL yang sama (/unsubscribe?…&signature=…). Email transaksional tetap terkirim.
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { MailCheck, MailX } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import CenterMessage from '@/components/common/CenterMessage/index.vue'
import { http } from '@/api/client'

const route = useRoute()
const email = computed(() => String(route.query.e ?? ''))
const valid = computed(() => Boolean(email.value && route.query.signature))
const state = ref('confirm') // confirm | done | error
const busy = ref(false)
const message = ref('')

async function confirm() {
  busy.value = true
  try {
    const qs = new URLSearchParams(route.query).toString()
    await http.post(`/unsubscribe?${qs}`)
    state.value = 'done'
  } catch (e) {
    state.value = 'error'
    message.value = e.status === 403 ? 'Tautan ini tidak valid. Gunakan tautan terbaru dari email Anda.' : e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <CenterMessage v-if="!valid" title="Tautan tidak lengkap" :icon="MailX" tone="danger" text="Buka tautan berhenti berlangganan langsung dari email TapCard." />
  <CenterMessage v-else-if="state === 'done'" title="Anda sudah berhenti berlangganan" :icon="MailCheck" tone="ok" badge="Selesai"
    :text="`${email} tidak akan menerima email promo TapCard lagi. Email penting akun (login, tagihan, pesanan) tetap dikirim.`" />
  <CenterMessage v-else-if="state === 'error'" title="Gagal berhenti berlangganan" :icon="MailX" tone="danger" :text="message">
    <AppButton variant="secondary" @click="state = 'confirm'">Coba lagi</AppButton>
  </CenterMessage>
  <CenterMessage v-else title="Berhenti berlangganan email promo?" :icon="MailX" badge="Email promo"
    :text="`Alamat ${email} tidak akan menerima email promo dan penawaran dari TapCard. Email penting akun tetap dikirim.`">
    <AppButton :loading="busy" @click="confirm">Ya, berhenti berlangganan</AppButton>
  </CenterMessage>
</template>
