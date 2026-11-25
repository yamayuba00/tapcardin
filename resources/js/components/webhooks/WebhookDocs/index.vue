<script setup>
// Dokumentasi integrasi webhook: katalog event + contoh JSON, cara verifikasi tanda tangan, kebijakan kirim ulang.
import { computed, ref } from 'vue'
import { KeyRound } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import DocCode from '@/components/webhooks/DocCode/index.vue'

const props = defineProps({
  catalog: { type: Object, required: true },
  endpointUrl: { type: String, default: '' },
  secret: { type: String, default: '' },
})
const emit = defineEmits(['rotate'])

const SECRET_PLACEHOLDER = 'rahasia-webhook-anda'
const URL_PLACEHOLDER = 'https://server-anda.example/webhooks/tapcard'
const TABS = [
  { value: 'events', label: 'Event & contoh' },
  { value: 'signature', label: 'Tanda tangan' },
  { value: 'retry', label: 'Kirim ulang & tips' },
]
const HEADERS = [
  ['X-TapCard-Event', 'Jenis event, mis. lead.created'],
  ['X-TapCard-Delivery', 'ID pengiriman. Sama di setiap percobaan ulang otomatis.'],
  ['X-TapCard-Timestamp', 'Waktu kirim, detik Unix. Ikut ditandatangani.'],
  ['X-TapCard-Signature', 'v1= lalu HMAC-SHA256 dalam hex.'],
]

const tab = ref('events')
const picked = ref(props.catalog.events[0]?.key)
const event = computed(() => props.catalog.events.find((e) => e.key === picked.value))
const groups = computed(() => {
  const out = {}
  props.catalog.events.forEach((e) => (out[e.group] ??= []).push(e))
  return out
})
const json = (v) => JSON.stringify(v, null, 2)
const RETRY_LABEL = (s) => (s < 3600 ? `${s / 60} menit` : `${s / 3600} jam`)
const secretValue = computed(() => props.secret || SECRET_PLACEHOLDER)
const urlValue = computed(() => props.endpointUrl || URL_PLACEHOLDER)

// Contoh cURL: body = JSON contoh event yang dipilih, persis yang ditandatangani lalu dikirim.
const curlSnippet = computed(() => {
  const ev = event.value ?? props.catalog.events[0]
  const body = JSON.stringify(ev?.sample ?? {})
  return `SECRET='${secretValue.value}'
URL='${urlValue.value}'
TS=$(date +%s)
BODY='${body}'
SIG=$(printf '%s.%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')

curl -sS -X POST "$URL" \\
  -H 'Content-Type: application/json' \\
  -H 'X-TapCard-Event: ${ev?.key ?? 'ping'}' \\
  -H "X-TapCard-Timestamp: $TS" \\
  -H "X-TapCard-Signature: v1=$SIG" \\
  -H 'User-Agent: TapCard-Webhooks/1.0' \\
  -d "$BODY" -w '\\nHTTP %{http_code}\\n'`
})

// Sisi penerima: simpan body mentah, hitung ulang tanda tangan dari header yang masuk, lalu tolak bila tidak cocok.
const verifySnippet = computed(() => `SECRET='${secretValue.value}'
TIMESTAMP=1767225600   # dari header X-TapCard-Timestamp
SIGNATURE='v1=...'     # dari header X-TapCard-Signature
EXPECT="v1=$(printf '%s.' "$TIMESTAMP" | cat - /tmp/body.json | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')"
AGE=$(( $(date +%s) - TIMESTAMP ))

[ "$EXPECT" = "$SIGNATURE" ] || { echo "401 tanda tangan salah"; exit 1; }
[ "$AGE" -ge -300 ] && [ "$AGE" -le 300 ] || { echo "401 timestamp kedaluwarsa"; exit 1; }
echo "200 diterima"`)
</script>

<template>
  <div class="grid min-w-0 gap-5">
    <AppSegmented v-model="tab" label="Dokumentasi" :options="TABS" />

    <!-- Event & contoh payload -->
    <div v-if="tab === 'events'" class="grid min-w-0 gap-5 lg:grid-cols-[240px_minmax(0,1fr)]">
      <nav class="grid content-start gap-4" aria-label="Daftar event">
        <div v-for="(list, group) in groups" :key="group" class="grid gap-1">
          <span class="label px-3">{{ group }}</span>
          <button
            v-for="e in list" :key="e.key" type="button" :aria-pressed="picked === e.key"
            class="cursor-pointer rounded-md px-3 py-2 text-left font-mono text-sm hover:bg-surface-2 aria-pressed:bg-surface-2 aria-pressed:text-accent-text"
            @click="picked = e.key"
          >{{ e.key }}</button>
        </div>
      </nav>
      <article v-if="event" class="grid min-w-0 content-start gap-4">
        <header class="grid gap-1">
          <h3 class="font-mono text-base">{{ event.key }}</h3>
          <p class="text-sm text-muted">{{ event.label }}. {{ event.description }}</p>
        </header>
        <DocCode label="Contoh payload (JSON)" :code="json(event.sample)" />
      </article>
    </div>

    <!-- Tanda tangan: 4 langkah berurutan -->
    <div v-else-if="tab === 'signature'" class="grid min-w-0 gap-6">
      <section class="grid gap-3">
        <h3 class="text-base">1 · Header setiap pengiriman</h3>
        <p class="text-sm text-muted">Setiap pengiriman adalah <b class="text-fg">POST</b> dengan <span class="font-mono">Content-Type: application/json</span> dan header berikut.</p>
        <dl class="grid min-w-0 overflow-hidden rounded-md border border-line text-sm sm:grid-cols-2">
          <div v-for="[h, d] in HEADERS" :key="h" class="grid min-w-0 gap-0.5 border-b border-line p-3 sm:odd:border-r">
            <dt class="font-mono break-all">{{ h }}</dt>
            <dd class="text-muted">{{ d }}</dd>
          </div>
        </dl>
      </section>

      <section class="grid gap-3">
        <h3 class="text-base">2 · Rahasia</h3>
        <div class="flex items-start gap-3 rounded-md border border-line p-3 max-sm:flex-wrap sm:items-center">
          <KeyRound class="size-5 flex-none text-accent-text" aria-hidden="true" />
          <p class="min-w-0 flex-1 basis-56 text-sm text-muted">
            {{ props.secret ? 'Sudah terisi di contoh di bawah dan tersimpan di server.' : 'Rahasia tidak bisa dilihat ulang. Buat baru: langsung tersimpan di server dan mengisi contoh. Rahasia lama tidak berlaku.' }}
          </p>
          <AppButton variant="secondary" :disabled="!endpointUrl" @click="emit('rotate')">Buat rahasia baru</AppButton>
        </div>
        <p v-if="!endpointUrl" class="text-xs text-muted">Contoh memakai alamat placeholder. Tambah endpoint di atas untuk memakai alamat dan rahasia Anda.</p>
      </section>

      <section class="grid min-w-0 gap-3">
        <h3 class="text-base">3 · Uji kirim dengan cURL</h3>
        <p class="text-sm text-muted">Kirim event contoh yang sudah ditandatangani ke endpoint Anda. Balasan 2xx berarti server menerima.</p>
        <DocCode label="cURL" :code="curlSnippet" />
      </section>

      <section class="grid min-w-0 gap-3">
        <h3 class="text-base">4 · Verifikasi di server Anda</h3>
        <p class="text-sm text-muted">Simpan body mentah ke <span class="font-mono">/tmp/body.json</span>, lalu hitung ulang tanda tangan dan tolak timestamp yang lebih tua dari 5 menit.</p>
        <DocCode label="Verifikasi (shell)" :code="verifySnippet" />
      </section>
    </div>

    <!-- Kirim ulang & aturan -->
    <div v-else class="grid min-w-0 gap-5">
      <div class="grid min-w-0 gap-3 md:grid-cols-2">
        <section class="grid content-start gap-1 rounded-md border border-line p-4 text-sm">
          <h3 class="text-base">Sukses dan gagal</h3>
          <p class="text-muted">Sukses = balasan HTTP 2xx dalam 5 detik. Redirect tidak diikuti dan dihitung gagal.</p>
        </section>
        <section class="grid content-start gap-1 rounded-md border border-line p-4 text-sm">
          <h3 class="text-base">Kirim ulang otomatis</h3>
          <p class="text-muted">Gagal dicoba ulang {{ catalog.retry.length }} kali dengan jeda {{ catalog.retry.map(RETRY_LABEL).join(', ') }}, sekitar 21 jam. Setelah itu bisa dikirim ulang manual dari log.</p>
        </section>
        <section class="grid content-start gap-1 rounded-md border border-line p-4 text-sm">
          <h3 class="text-base">Nonaktif otomatis</h3>
          <p class="text-muted">Endpoint dinonaktifkan setelah {{ catalog.disable_after }} kali gagal berturut-turut. Owner dan admin diberi tahu. Aktifkan lagi setelah server diperbaiki.</p>
        </section>
        <section class="grid content-start gap-1 rounded-md border border-line p-4 text-sm">
          <h3 class="text-base">Cegah proses ganda</h3>
          <p class="text-muted">Percobaan ulang otomatis memakai <span class="font-mono">X-TapCard-Delivery</span> yang sama. Kirim ulang manual memakai delivery baru dengan <span class="font-mono">id</span> event yang sama. Simpan <span class="font-mono">id</span> yang sudah diproses dan abaikan duplikatnya.</p>
        </section>
        <section class="grid content-start gap-1 rounded-md border border-line p-4 text-sm">
          <h3 class="text-base">Urutan dan retensi</h3>
          <p class="text-muted">Urutan event tidak dijamin. Pakai <span class="font-mono">created_at</span> bila urutan penting. Log pengiriman disimpan 30 hari. Payload hanya berisi ID publik dan tidak pernah berisi rahasia.</p>
        </section>
      </div>
      <DocCode label="Uji kirim (ping)" :code="json(catalog.ping)" />
    </div>
  </div>
</template>
