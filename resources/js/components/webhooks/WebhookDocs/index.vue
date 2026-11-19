<script setup>
// Dokumentasi integrasi di halaman Webhook: katalog event + contoh JSON, verifikasi tanda tangan, kebijakan kirim ulang.
import { computed, ref } from 'vue'
import { Copy } from 'lucide-vue-next'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import { useToastStore } from '@/stores/toast'

const props = defineProps({ catalog: { type: Object, required: true } })
const toast = useToastStore()
const tab = ref('events')
const lang = ref('php')
const picked = ref(props.catalog.events[0]?.key)
const event = computed(() => props.catalog.events.find((e) => e.key === picked.value))
const groups = computed(() => {
  const out = {}
  props.catalog.events.forEach((e) => (out[e.group] ??= []).push(e))
  return out
})
const json = (v) => JSON.stringify(v, null, 2)
const RETRY_LABEL = (s) => (s < 3600 ? `${s / 60} menit` : `${s / 3600} jam`)

const SNIPPETS = {
  php: `<?php
// Laravel / PHP murni — tolak bila tanda tangan salah atau lebih tua dari 5 menit
$secret = getenv('TAPCARD_WEBHOOK_SECRET');
$body = file_get_contents('php://input');            // body mentah, jangan di-decode dulu
$ts = $_SERVER['HTTP_X_TAPCARD_TIMESTAMP'] ?? '';
$sig = $_SERVER['HTTP_X_TAPCARD_SIGNATURE'] ?? '';
$expected = 'v1=' . hash_hmac('sha256', $ts . '.' . $body, $secret);

if (!hash_equals($expected, $sig) || abs(time() - (int) $ts) > 300) {
    http_response_code(401);
    exit;
}
$event = json_decode($body, true);
// simpan $event['id'] untuk mencegah proses ganda, lalu balas cepat (< 5 detik)
http_response_code(200);`,
  node: `// Node.js (Express) — pakai body mentah
const crypto = require('crypto')
app.post('/webhooks/tapcard', express.raw({ type: 'application/json' }), (req, res) => {
  const ts = req.get('X-TapCard-Timestamp')
  const sig = req.get('X-TapCard-Signature') || ''
  const expected = 'v1=' + crypto.createHmac('sha256', process.env.TAPCARD_WEBHOOK_SECRET)
    .update(ts + '.' + req.body).digest('hex')
  const ok = sig.length === expected.length && crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(expected))
  if (!ok || Math.abs(Date.now() / 1000 - Number(ts)) > 300) return res.sendStatus(401)

  const event = JSON.parse(req.body)
  // simpan event.id untuk mencegah proses ganda; kerjakan yang berat di antrean
  res.sendStatus(200)
})`,
  python: `# Python (Flask)
import hashlib, hmac, os, time
from flask import Flask, request, abort

app = Flask(__name__)

@app.post('/webhooks/tapcard')
def tapcard():
    body = request.get_data()  # bytes mentah
    ts = request.headers.get('X-TapCard-Timestamp', '')
    sig = request.headers.get('X-TapCard-Signature', '')
    expected = 'v1=' + hmac.new(os.environ['TAPCARD_WEBHOOK_SECRET'].encode(),
                                f'{ts}.'.encode() + body, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, sig) or abs(time.time() - int(ts or 0)) > 300:
        abort(401)
    event = request.get_json()
    # simpan event['id'] untuk mencegah proses ganda
    return '', 200`,
}
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
      <AppSegmented v-model="lang" label="Bahasa" :options="[{ value: 'php', label: 'PHP' }, { value: 'node', label: 'Node.js' }, { value: 'python', label: 'Python' }]" />
      <div class="relative">
        <button type="button" class="absolute top-2 right-2 grid size-9 cursor-pointer place-items-center rounded-md bg-surface text-muted hover:text-fg" aria-label="Salin kode" @click="copy(SNIPPETS[lang])"><Copy class="size-4" /></button>
        <pre class="max-h-[480px] overflow-auto rounded-md bg-surface-2 p-4 font-mono text-xs">{{ SNIPPETS[lang] }}</pre>
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
