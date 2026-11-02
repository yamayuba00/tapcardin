<script setup>
// "Masuk dengan kartu NFC" — dua faktor: kartu fisik (token di URL chip + UID chip, dibaca Web NFC) dan PIN 6 digit.
// Web NFC hanya ada di Chrome Android; di perangkat lain tombol diganti keterangan agar memakai Google.
// PIN diatur pemegang di Kartu → detail kartu → "Masuk dengan kartu ini".
import { onBeforeUnmount, reactive, ref } from 'vue'
import { CreditCard, Nfc } from 'lucide-vue-next'
import AltchaField from '@/components/auth/AltchaField/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppCheckbox from '@/components/ui/AppCheckbox/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import { authApi } from '@/api/auth'
import { cardTokenFromUrl, nfcErrorMessage, nfcSupported, readOnce } from '@/lib/nfc'

const emit = defineEmits(['success'])
const supported = nfcSupported()
const step = ref('start') // start | scanning | pin
const card = ref(null) // { token, uid }
const form = reactive({ pin: '', remember: true, altcha: null })
const error = ref('')
const pinError = ref('')
const busy = ref(false)
const altchaRef = ref(null)
let ctrl = null

async function scan() {
  error.value = ''
  ctrl = new AbortController()
  step.value = 'scanning'
  try {
    const { serialNumber, url } = await readOnce({ signal: ctrl.signal })
    const token = cardTokenFromUrl(url)
    if (!token || !serialNumber) throw new Error('Kartu ini bukan kartu TapCard, atau isinya tidak terbaca.')
    card.value = { token, uid: serialNumber }
    step.value = 'pin'
  } catch (e) {
    if (e?.name !== 'AbortError') error.value = nfcErrorMessage(e)
    step.value = 'start'
  } finally {
    ctrl?.abort()
    ctrl = null
  }
}

function cancel() {
  ctrl?.abort()
  card.value = null
  form.pin = ''
  pinError.value = ''
  step.value = 'start'
}

async function submit() {
  pinError.value = ''
  if (!/^\d{6}$/.test(form.pin)) return (pinError.value = 'PIN terdiri dari 6 angka.')
  if (!form.altcha) return (pinError.value = 'Tunggu verifikasi anti-robot selesai (centang otomatis).')
  busy.value = true
  try {
    await authApi.cardLogin({ ...card.value, pin: form.pin, remember: form.remember, altcha: form.altcha })
    emit('success')
  } catch (e) {
    pinError.value = e.fields?.pin?.[0] ?? e.fields?.altcha?.[0] ?? e.fields?.token?.[0] ?? e.message
    form.pin = ''
    altchaRef.value?.reset() // payload sekali pakai
    busy.value = false
  }
}

onBeforeUnmount(() => ctrl?.abort())
</script>

<template>
  <div class="grid gap-3">
    <template v-if="!supported">
      <AppButton variant="secondary" block disabled aria-describedby="card-login-hint"><Nfc class="size-5" /> Masuk dengan kartu NFC</AppButton>
      <p id="card-login-hint" class="text-center text-xs text-muted">Masuk dengan kartu butuh Chrome di Android dengan NFC menyala. Di iPhone atau komputer, masuk dengan Google.</p>
    </template>

    <template v-else-if="step === 'start'">
      <AppButton variant="secondary" block @click="scan"><Nfc class="size-5" /> Masuk dengan kartu NFC</AppButton>
      <p v-if="error" class="rounded-md border border-line-strong bg-surface-2 px-3 py-2 text-sm text-danger" role="alert">{{ error }}</p>
    </template>

    <div v-else-if="step === 'scanning'" class="grid justify-items-center gap-3 rounded-md border border-line-strong bg-surface-2 p-6 text-center" aria-live="polite">
      <Nfc class="size-10 animate-pulse text-info" aria-hidden="true" />
      <p class="font-medium">Tempelkan kartu TapCard ke bagian belakang ponsel</p>
      <p class="text-sm text-muted">Tahan sampai ponsel bergetar. Area NFC biasanya dekat kamera.</p>
      <AppButton variant="ghost" @click="cancel">Batal</AppButton>
    </div>

    <form v-else class="grid gap-4 rounded-md border border-line-strong p-4" novalidate @submit.prevent="submit">
      <p class="flex items-center gap-2 text-sm"><CreditCard class="size-5 text-ok" aria-hidden="true" /> Kartu terbaca. Masukkan PIN kartu Anda.</p>
      <AppField label="PIN kartu" hint="6 angka yang Anda atur di detail kartu." :error="pinError">
        <AppInput
          v-model.trim="form.pin" code type="password" inputmode="numeric" pattern="\d{6}" maxlength="6"
          autocomplete="off" autofocus :invalid="!!pinError" aria-label="PIN kartu 6 angka"
        />
      </AppField>
      <AppCheckbox v-model="form.remember" label="Ingat saya 7 hari" />
      <AltchaField ref="altchaRef" v-model="form.altcha" />
      <div class="grid gap-2">
        <AppButton type="submit" block :loading="busy">Masuk</AppButton>
        <AppButton variant="ghost" block :disabled="busy" @click="cancel">Pakai kartu lain</AppButton>
      </div>
    </form>
  </div>
</template>
