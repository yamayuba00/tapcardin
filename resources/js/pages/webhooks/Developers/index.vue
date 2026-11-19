<script setup>
// wbh.{base} — dokumentasi publik webhook keluar untuk developer klien. Isi katalog sama persis dengan halaman
// /webhooks di dashboard (komponen WebhookDocs + katalog dari server), jadi tidak pernah berbeda.
import { onMounted, ref } from 'vue'
import { ShieldCheck, Webhook } from 'lucide-vue-next'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import BrandLogo from '@/components/common/BrandLogo/index.vue'
import WebhookDocs from '@/components/webhooks/WebhookDocs/index.vue'
import { http } from '@/api/client'
import { config } from '@/config'

const catalog = ref(null)
const error = ref('')
onMounted(async () => {
  try {
    catalog.value = (await http.get('/api/catalog')).data
  } catch (e) {
    error.value = e.message
  }
})
const HEADERS = [
  ['X-TapCard-Event', 'Jenis event, mis. lead.created'],
  ['X-TapCard-Delivery', 'ID unik pengiriman — pakai untuk idempotensi (abaikan bila sudah pernah diproses)'],
  ['X-TapCard-Timestamp', 'Waktu kirim (detik Unix), ikut ditandatangani'],
  ['X-TapCard-Signature', 'v1=<hex HMAC-SHA256(rahasia, timestamp + "." + body)>'],
  ['User-Agent', 'TapCard-Webhooks/1.0'],
]
</script>

<template>
  <div class="min-h-dvh bg-bg">
    <header class="border-b border-line">
      <div class="mx-auto flex h-16 max-w-5xl items-center justify-between px-4">
        <a :href="config.marketingUrl || '/'" class="no-underline" aria-label="TapCard"><BrandLogo /></a>
        <span class="label">Developer · Webhook</span>
      </div>
    </header>

    <main class="mx-auto grid max-w-5xl gap-6 px-4 py-8">
      <div class="grid gap-2">
        <h1 class="flex items-center gap-3"><Webhook class="size-8 text-accent-text" aria-hidden="true" /> Webhook TapCard</h1>
        <p class="max-w-2xl text-muted">
          TapCard mengirim HTTP POST berisi JSON ke server Anda setiap kali sesuatu terjadi di workspace perusahaan — lead baru,
          lead ditugaskan, kartu diaktifkan, peserta check-in di event, dan lainnya. Endpoint diatur owner/admin workspace di
          menu <b>Perusahaan → Webhook</b> (paket Business).
        </p>
      </div>

      <div class="grid gap-4 md:grid-cols-2">
        <AppPanel title="Header setiap pengiriman">
          <dl class="grid gap-3 text-sm">
            <div v-for="[h, d] in HEADERS" :key="h" class="grid gap-0.5">
              <dt class="font-mono text-fg">{{ h }}</dt><dd class="text-muted">{{ d }}</dd>
            </div>
          </dl>
        </AppPanel>
        <AppPanel title="Keamanan">
          <ul class="grid gap-3 text-sm">
            <li class="flex gap-2"><ShieldCheck class="mt-0.5 size-4 flex-none text-ok" aria-hidden="true" />Verifikasi tanda tangan di setiap permintaan dan tolak yang lebih tua dari 5 menit (cegah replay).</li>
            <li class="flex gap-2"><ShieldCheck class="mt-0.5 size-4 flex-none text-ok" aria-hidden="true" />URL tujuan wajib HTTPS dan harus ke alamat IP publik — alamat internal/privat ditolak, juga saat pengiriman. Redirect tidak diikuti.</li>
            <li class="flex gap-2"><ShieldCheck class="mt-0.5 size-4 flex-none text-ok" aria-hidden="true" />Payload hanya berisi ID publik (ULID / token kartu) — tidak ada ID internal, hash, atau rahasia.</li>
            <li class="flex gap-2"><ShieldCheck class="mt-0.5 size-4 flex-none text-ok" aria-hidden="true" />Balas 2xx dalam 5 detik; kerjakan proses berat di antrean Anda sendiri.</li>
          </ul>
        </AppPanel>
      </div>

      <AppSkeleton v-if="!catalog && !error" class="!h-80" />
      <p v-else-if="error" class="text-danger" role="alert">{{ error }}</p>
      <AppPanel v-else title="Event, contoh JSON, & kode verifikasi"><WebhookDocs :catalog="catalog" /></AppPanel>
    </main>
  </div>
</template>
