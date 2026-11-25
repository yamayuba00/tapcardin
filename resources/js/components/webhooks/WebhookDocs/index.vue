<script setup>
// Dokumentasi integrasi di halaman Webhook: katalog event + contoh JSON, verifikasi tanda tangan, kebijakan kirim ulang.
import { computed, ref } from 'vue'
import { Copy } from 'lucide-vue-next'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import { useToastStore } from '@/stores/toast'

const props = defineProps({ catalog: { type: Object, required: true } })
const toast = useToastStore()
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

// Contoh cURL memakai event yang sedang dipilih: body = JSON contoh yang persis dikirim, lalu ditandatangani.
const curlSnippet = computed(() => {
  const ev = event.value ?? props.catalog.events[0]
  const body = JSON.stringify(ev?.sample ?? {})
  return `# 1) Uji endpoint Anda: kirim event contoh yang sudah ditandatangani
SECRET='rahasia-webhook-anda'
URL='https://server-anda.example/webhooks/tapcard'
TS=$(date +%s)
BODY='${body}'
SIG=$(printf '%s.%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')

curl -sS -X POST "$URL" \
  -H 'Content-Type: application/json' \
  -H 'X-TapCard-Event: ${ev?.key ?? 'ping'}' \
  -H "X-TapCard-Timestamp: $TS" \
  -H "X-TapCard-Signature: v1=$SIG" \
  -H 'User-Agent: TapCard-Webhooks/1.0' \
  -d "$BODY" -w '\nHTTP %{http_code}\n'`
})

// Sisi penerima: simpan body mentah dulu, lalu hitung ulang tanda tangan dengan header yang masuk.
const verifySnippet = `# 2) Verifikasi di server Anda (body mentah disimpan ke /tmp/body.json)
SECRET='rahasia-webhook-anda'  # rahasia dari halaman Webhook
TIMESTAMP=1767225600  # isi dari header X-TapCard-Timestamp
SIGNATURE='v1=...'  # isi dari header X-TapCard-Signature
EXPECT="v1=$(printf '%s.' "$TIMESTAMP" | cat - /tmp/body.json | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')"

[ "$EXPECT" = "$SIGNATURE" ] || { echo "401 tanda tangan salah"; exit 1; }
AGE=$(( $(date +%s) - TIMESTAMP ))
[ "$AGE" -ge -300 ] && [ "$AGE" -le 300 ] || { echo "401 timestamp kedaluwarsa"; exit 1; }
echo "200 diterima"`
async function copy(text) {
  try { await navigator.clipboard.writeText(text); toast.ok('Disalin.') } catch { toast.error('Tidak bisa menyalin.') }
}
</script>

<template>
  <div class="grid gap-4">
    <AppSegmented v-model="tab" label="Dokumentasi" :options="[{ value: 'events', label: 'Event & contoh' }, { value: 'signature', label: 'Tanda tangan' }, { value: 'retry', label: 'Kirim ulang & tips' }]" />

    <div v-if="tab === 'events'" class="grid gap-4 lg:grid-cols-[minmax(0,260px)_minmax(0,1fr)]">
      <nav class="grid content-start gap-3" aria-label="Daftar event">
        <div v-for="(list, group) in groups" :key="group" class="grid gap-1">
          <span class="label">{{ group }}</span>
          <button v-for="e in list" :key="e.key" type="button" :aria-pressed="picked === e.key"
            class="cursor-pointer rounded-md px-3 py-2 text-left font-mono text-sm hover:bg-surface-2 aria-pressed:bg-surface-2 aria-pressed:text-accent-text"
            @click="picked = e.key">{{ e.key }}</button>
        </div>
      </nav>
      <article v-if="event" class="grid min-w-0 content-start gap-3">
        <div>
          <h3 class="font-mono">{{ event.key }}</h3>
          <p class="text-sm text-muted">{{ event.label }} — {{ event.description }}</p>
        </div>
        <div class="relative">
          <button type="button" class="absolute top-2 right-2 grid size-9 cursor-pointer place-items-center rounded-md bg-surface text-muted hover:text-fg" aria-label="Salin contoh JSON" @click="copy(json(event.sample))"><Copy class="size-4" /></button>
          <pre class="max-h-[480px] overflow-auto rounded-md bg-surface-2 p-4 font-mono text-xs">{{ json(event.sample) }}</pre>
        </div>
      </article>
    </div>

    <div v-else-if="tab === 'signature'" class="grid gap-3 text-sm">
      <p>Setiap permintaan adalah <b>POST</b> JSON (<span class="font-mono">Content-Type: application/json</span>) dengan header:</p>
      <ul class="grid gap-1 font-mono text-xs">
        <li><b>X-TapCard-Event</b> — jenis event, mis. lead.created</li>
        <li><b>X-TapCard-Delivery</b> — ID pengiriman (sama di setiap percobaan ulang otomatis)</li>
        <li><b>X-TapCard-Timestamp</b> — waktu kirim (detik Unix)</li>
        <li><b>X-TapCard-Signature</b> — v1=HMAC-SHA256(rahasia, timestamp + "." + body) dalam hex</li>
      </ul>
      <p class="text-muted">Hitung ulang tanda tangan dari body <b>mentah</b>, bandingkan dengan perbandingan waktu-konstan, dan tolak timestamp yang lebih tua dari 5 menit.</p>
      <div class="relative">
        <button type="button" class="absolute top-2 right-2 grid size-9 cursor-pointer place-items-center rounded-md bg-surface text-muted hover:text-fg" aria-label="Salin contoh cURL" @click="copy(curlSnippet)"><Copy class="size-4" /></button>
        <pre class="max-h-[480px] overflow-auto rounded-md bg-surface-2 p-4 font-mono text-xs">{{ curlSnippet }}</pre>
      </div>
      <div class="relative">
        <button type="button" class="absolute top-2 right-2 grid size-9 cursor-pointer place-items-center rounded-md bg-surface text-muted hover:text-fg" aria-label="Salin kode verifikasi" @click="copy(verifySnippet)"><Copy class="size-4" /></button>
        <pre class="max-h-[480px] overflow-auto rounded-md bg-surface-2 p-4 font-mono text-xs">{{ verifySnippet }}</pre>
      </div>
    </div>

    <div v-else class="grid gap-3 text-sm">
      <p><b>Sukses</b> = balasan HTTP 2xx dalam 5 detik. Redirect tidak diikuti dan dihitung gagal.</p>
      <p><b>Gagal</b> dicoba ulang otomatis {{ catalog.retry.length }} kali dengan jeda {{ catalog.retry.map(RETRY_LABEL).join(', ') }} — total sekitar 21 jam. Setelah itu status pengiriman menjadi Gagal dan bisa dikirim ulang manual dari log.</p>
      <p>Endpoint <b>dinonaktifkan otomatis</b> setelah {{ catalog.disable_after }} kali gagal berturut-turut; owner &amp; admin diberi tahu. Aktifkan lagi setelah server diperbaiki.</p>
      <p><b>Cegah proses ganda</b>: percobaan ulang otomatis memakai <span class="font-mono">X-TapCard-Delivery</span> yang sama, sedangkan kirim ulang manual memakai delivery baru dengan <span class="font-mono">id</span> event yang sama. Simpan <span class="font-mono">id</span> event yang sudah diproses dan abaikan duplikatnya.</p>
      <p>Urutan event tidak dijamin. Pakai <span class="font-mono">created_at</span> dan data terbaru di sistem Anda bila urutan penting.</p>
      <p>Log pengiriman disimpan 30 hari. Body hanya berisi ID publik (bukan ID internal) dan tidak pernah berisi rahasia.</p>
      <p class="text-muted">Uji kirim mengirim event <span class="font-mono">ping</span>:</p>
      <pre class="overflow-auto rounded-md bg-surface-2 p-4 font-mono text-xs">{{ json(catalog.ping) }}</pre>
    </div>
  </div>
</template>
