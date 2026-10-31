<script setup>
// ALTCHA — verifikasi anti-bot proof-of-work (tanpa layanan pihak ketiga, tanpa cookie pelacak).
// Widget mengambil challenge dari /api/auth/altcha, menyelesaikannya di browser, lalu memberi payload yang
// dikirim bersama formulir. Setiap payload sekali pakai → setelah submit panggil reset() untuk challenge baru.
import { onMounted, ref } from 'vue'

const model = defineModel({ type: [String, null], default: null })
defineProps({ error: String })
const el = ref(null)
const ready = ref(false)
// opsi di luar atribut bawaan widget harus lewat `configuration` (JSON)
const CONFIG = JSON.stringify({ hideFooter: true, hideLogo: false, minDuration: 300 })

// Build `external` agar lolos CSP ketat: tanpa <style> sisipan (CSS jadi file) & tanpa worker blob/data: —
// worker SHA dimuat sebagai file hasil build Vite (script-src 'self'). Hanya SHA-256 yang dipakai server.
onMounted(async () => {
  await Promise.all([import('altcha/external'), import('altcha/altcha.css')])
  const [{ default: ShaWorker }] = await Promise.all([import('altcha/workers/sha?worker'), import('altcha/i18n/id')])
  window.$altcha.algorithms.set('SHA-256', () => new ShaWorker())
  ready.value = true
})

function onState(e) {
  model.value = e.detail?.state === 'verified' ? e.detail.payload : null
}

/** Challenge baru (payload lama sudah terpakai di server) dan langsung diverifikasi ulang. */
function reset() {
  model.value = null
  el.value?.reset?.()
  el.value?.verify?.()
}
defineExpose({ reset })
</script>

<template>
  <div class="grid gap-1.5">
    <altcha-widget
      v-if="ready" ref="el" class="altcha-tapcard" challenge="/api/auth/altcha" language="id" auto="onload" type="checkbox" :configuration="CONFIG"
      @statechange="onState"
    ></altcha-widget>
    <div v-else class="h-[58px] animate-skeleton rounded-md bg-surface-2" aria-hidden="true"></div>
    <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>
  </div>
</template>

<style>
/* Selaraskan widget dengan token desain (tanpa hex — memakai variabel tokens.css). */
.altcha-tapcard {
  --altcha-max-width: 100%;
  --altcha-border-radius: 8px;
  --altcha-border-color: var(--line-strong);
  --altcha-color-base: var(--surface-1);
  --altcha-color-base-content: var(--text);
  --altcha-color-primary: var(--accent);
  --altcha-color-primary-content: var(--accent-ink);
  --altcha-color-success: var(--solid-ok);
  --altcha-color-error: var(--danger);
  --altcha-color-neutral: var(--surface-2);
  --altcha-color-neutral-content: var(--text-2);
  --altcha-input-background-color: var(--surface-1);
}
</style>
