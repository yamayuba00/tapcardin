<script setup>
// Isi halaman /login/kartu — dua faktor: bukti kartu fisik + kode akun 6 angka.
// Bukti kartu: (a) Chrome Android → Web NFC membaca token + UID chip di halaman ini; atau
// (b) HP mana pun (termasuk iPhone) → tap kartu membuka profil, lalu tautan "Masuk" membawa token + kunci chip
//     (prop `preset`) ke sini. Kode akun dibuat saat aktivasi di tempat atau diatur di detail kartu.
import { onBeforeUnmount, reactive, ref } from 'vue'
import { CreditCard, Nfc } from 'lucide-vue-next'
import AltchaField from '@/components/auth/AltchaField/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppCheckbox from '@/components/ui/AppCheckbox/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import { authApi } from '@/api/auth'
import { cardTokenFromUrl, nfcErrorMessage, nfcSupported, readOnce } from '@/lib/nfc'

const props = defineProps({ preset: { type: Object, default: null } }) // { token, key } dari tap kartu
const emit = defineEmits(['success'])
const supported = nfcSupported()
const card = ref(props.preset) // { token, uid } | { token, key }
const step = ref(props.preset ? 'code' : 'start') // start | scanning | code
const form = reactive({ code: '', remember: true, altcha: null })
const error = ref('')
const codeError = ref('')
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
    const key = new URL(url).searchParams.get('k')
    card.value = { token, uid: serialNumber, ...(key ? { key } : {}) }
    step.value = 'code'
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
  form.code = ''
  codeError.value = ''
  step.value = 'start'
}

async function submit() {
  codeError.value = ''
  if (!/^\d{6}$/.test(form.code)) return (codeError.value = 'Kode akun terdiri dari 6 angka.')
  if (!form.altcha) return (codeError.value = 'Tunggu verifikasi anti-robot selesai (centang otomatis).')
  busy.value = true
  try {
    await authApi.cardLogin({ ...card.value, code: form.code, remember: form.remember, altcha: form.altcha })
    emit('success')
  } catch (e) {
    const f = e.fields ?? {}
    codeError.value = f.code?.[0] ?? f.altcha?.[0] ?? f.token?.[0] ?? f.key?.[0] ?? f.uid?.[0] ?? e.message
    form.code = ''
    altchaRef.value?.reset() // payload sekali pakai
    busy.value = false
  }
}

onBeforeUnmount(() => ctrl?.abort())
</script>

<template>
  <div class="grid gap-3">
    <form v-if="step === 'code'" class="grid gap-4 rounded-md border border-line-strong p-4" novalidate @submit.prevent="submit">
      <p class="flex items-center gap-2 text-sm"><CreditCard class="size-5 text-ok" aria-hidden="true" /> Kartu dikenali. Masukkan kode akun Anda.</p>
      <AppField label="Kode akun" hint="6 angka dari petugas saat kartu diaktifkan, atau yang Anda atur sendiri." :error="codeError">
        <AppInput
          v-model.trim="form.code" code type="password" inputmode="numeric" pattern="\d{6}" maxlength="6"
          autocomplete="off" autofocus :invalid="!!codeError" aria-label="Kode akun 6 angka"
        />
      </AppField>
      <AppCheckbox v-model="form.remember" label="Ingat saya 7 hari" />
      <AltchaField ref="altchaRef" v-model="form.altcha" />
      <div class="grid gap-2">
        <AppButton type="submit" block :loading="busy">Masuk</AppButton>
        <AppButton variant="ghost" block :disabled="busy" @click="cancel">Pakai kartu lain</AppButton>
      </div>
    </form>

    <div v-else-if="step === 'scanning'" class="grid justify-items-center gap-3 rounded-md border border-line-strong bg-surface-2 p-6 text-center" aria-live="polite">
      <Nfc class="size-10 animate-pulse text-info" aria-hidden="true" />
      <p class="font-medium">Tempelkan kartu TapCard ke bagian belakang ponsel</p>
      <p class="text-sm text-muted">Tahan sampai ponsel bergetar. Area NFC biasanya dekat kamera.</p>
      <AppButton variant="ghost" @click="cancel">Batal</AppButton>
    </div>

    <!-- Chrome Android: pemindaian dimulai dengan ketukan (syarat izin NFC browser) -->
    <div v-else-if="supported" class="grid justify-items-center gap-4 rounded-md border border-line-strong p-6 text-center">
      <Nfc class="size-12 text-info" aria-hidden="true" />
      <p class="text-sm text-muted">Pastikan NFC di ponsel menyala, tekan tombol di bawah, lalu tempelkan kartu ke bagian belakang ponsel.</p>
      <AppButton size="lg" block @click="scan"><Nfc class="size-5" /> Pindai kartu</AppButton>
      <p v-if="error" class="w-full rounded-md border border-line-strong bg-surface-2 px-3 py-2 text-left text-sm text-danger" role="alert">{{ error }}</p>
    </div>

    <!-- iPhone / komputer: halaman web tidak bisa membaca NFC, tapi tap kartu membuka halamannya -->
    <div v-else class="grid gap-3 rounded-md border border-line-strong p-5 text-sm">
      <p class="flex items-center gap-2 font-medium"><Nfc class="size-5 text-info" aria-hidden="true" /> Cara masuk di iPhone / perangkat ini</p>
      <ol class="grid list-decimal gap-1 pl-5 text-muted">
        <li>Tempelkan kartu TapCard ke ponsel (iPhone: bagian atas belakang).</li>
        <li>Halaman kartu Anda terbuka — ketuk <b class="text-fg">Masuk sebagai pemilik</b> di bagian bawah.</li>
        <li>Ketik kode akun 6 angka.</li>
      </ol>
    </div>
  </div>
</template>
