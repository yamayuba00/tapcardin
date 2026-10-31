<script setup>
// U-16 — wizard 4 langkah (bisa dilewati): Profil → Foto → Kontak → Coba (tap terdeteksi lewat SSE + polling).
import { onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Camera, Eye, UserRound } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import TapZone from '@/components/common/TapZone/index.vue'
import { cardsApi, profilesApi } from '@/api/workspace'
import { toE164 } from '@/lib/format'
import { useNotificationStore } from '@/stores/notifications'
import { useToastStore } from '@/stores/toast'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()
const notifications = useNotificationStore()
const token = route.params.token

const card = ref(null)
const profile = ref(null) // bio link milik kartu ini
const step = ref(1)
const busy = ref(false)
const error = ref('')
const form = reactive({ display_name: '', title: '', company: '', whatsapp: '', phone: '', email: '' })

const CONTACT = ['whatsapp', 'phone', 'email']
const STEPS = 4

async function load() {
  const c = await cardsApi.get(token)
  profile.value = (await profilesApi.get(c.data.profile.id)).data
  card.value = c.data
}
load().catch(() => router.replace('/cards'))

watch(profile, (p) => {
  if (!p) return
  Object.assign(form, { display_name: p.display_name, title: p.title || '', company: p.company || '' })
  for (const t of CONTACT) form[t] = p.fields.find((f) => f.type === t && f.is_primary)?.value ?? p.fields.find((f) => f.type === t)?.value ?? ''
})

async function saveProfile() {
  error.value = ''
  if (!form.display_name.trim()) return (error.value = 'Isi nama yang tampil di profil.')
  busy.value = true
  try {
    await profilesApi.update(profile.value.id, { display_name: form.display_name, title: form.title || null, company: form.company || null })
    step.value = 2
  } catch (e) { error.value = e.message } finally { busy.value = false }
}

async function uploadPhoto(e) {
  const file = e.target.files?.[0]
  e.target.value = ''
  if (!file) return
  error.value = ''
  busy.value = true
  try {
    const { data } = await profilesApi.uploadAvatar(profile.value.id, file)
    profile.value.avatar_url = data.avatar_url
  } catch (err) { error.value = err.message } finally { busy.value = false }
}

async function saveContacts() {
  error.value = ''
  for (const k of ['whatsapp', 'phone']) {
    if (form[k] && !toE164(form[k])) return (error.value = `Nomor ${k === 'whatsapp' ? 'WhatsApp' : 'telepon'} belum valid.`)
  }
  busy.value = true
  try {
    // kontak utama diganti isian wizard; field lain (tautan, alamat, kontak sekunder) dipertahankan
    const keep = profile.value.fields.filter((f) => !(CONTACT.includes(f.type) && f.is_primary))
    const primary = [
      form.whatsapp && { type: 'whatsapp', label: 'WhatsApp', value: form.whatsapp, is_primary: true },
      form.phone && { type: 'phone', label: 'Telepon', value: form.phone, is_primary: true },
      form.email && { type: 'email', label: 'Email', value: form.email, is_primary: true },
    ].filter(Boolean)
    await profilesApi.saveFields(profile.value.id, [...primary, ...keep])
    step.value = 4
  } catch (e) { error.value = e.message } finally { busy.value = false }
}

// A3 — langkah Coba: tunggu tap pertama sejak langkah dibuka. SSE memberi sinyal instan;
// polling last_tap_at jadi cadangan bila stream terputus atau notifikasi tap dimatikan.
const tapped = ref(false)
let since = 0
let poll = null
let unlisten = null
function stopWatching() {
  clearInterval(poll)
  unlisten?.()
  poll = unlisten = null
}
function markTapped() {
  if (tapped.value) return
  tapped.value = true
  stopWatching()
}
watch(step, (n) => {
  stopWatching()
  if (n !== STEPS || tapped.value) return
  since = Date.now() - 2000 // toleransi selisih jam server
  unlisten = notifications.subscribe((type, data) => { if (type === 'tap' && data?.card === token) markTapped() })
  poll = setInterval(async () => {
    const { data } = await cardsApi.get(token).catch(() => ({ data: null }))
    if (data?.last_tap_at && Date.parse(data.last_tap_at) >= since) markTapped()
  }, 3000)
})
onBeforeUnmount(stopWatching)

function finish() {
  toast.ok('Kartu siap dipakai')
  router.push(`/cards/${token}`)
}
</script>

<template>
  <div class="mx-auto grid w-full max-w-[400px] gap-6">
    <div v-if="!card" class="grid gap-4"><AppSkeleton class="!h-8" /><AppSkeleton class="!h-56" /></div>
    <template v-else>
      <div class="flex items-center justify-between">
        <span class="label">Langkah {{ step }} dari {{ STEPS }}</span>
        <AppButton v-if="step < STEPS" variant="ghost" @click="step++">Lewati</AppButton>
      </div>

      <form v-if="step === 1" class="grid gap-4" @submit.prevent="saveProfile">
        <h1>Bio link kartu ini</h1>
        <p class="text-muted">Halaman yang terbuka saat kartu di-tap. Tema &amp; tautan bisa diatur lengkap setelah ini.</p>
        <AppField label="Nama"><AppInput v-model="form.display_name" maxlength="120" required /></AppField>
        <AppField label="Jabatan"><AppInput v-model="form.title" maxlength="120" placeholder="mis. Konsultan Pajak" /></AppField>
        <AppField label="Perusahaan"><AppInput v-model="form.company" maxlength="120" /></AppField>
        <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>
        <AppButton type="submit" block :loading="busy">Lanjut</AppButton>
      </form>

      <div v-else-if="step === 2" class="grid gap-4">
        <h1>Foto profil</h1>
        <p class="text-muted">Wajah yang jelas memudahkan orang mengingat Anda. Foto juga ikut tersimpan di kontak mereka.</p>
        <div class="grid justify-items-center gap-4 py-2">
          <img v-if="profile?.avatar_url" :src="profile.avatar_url" alt="Foto profil" class="size-32 rounded-full object-cover" />
          <span v-else class="grid size-32 place-items-center rounded-full bg-surface-2 text-muted"><UserRound class="size-12" aria-hidden="true" /></span>
          <label :class="['inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-md border border-line-strong px-5 font-semibold hover:bg-surface-2', busy ? 'pointer-events-none opacity-60' : '']">
            <Camera class="size-5" /> {{ busy ? 'Mengunggah…' : profile?.avatar_url ? 'Ganti foto' : 'Pilih foto' }}
            <input class="sr-only" type="file" accept="image/jpeg,image/png,image/webp" :disabled="busy" @change="uploadPhoto" />
          </label>
        </div>
        <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>
        <AppButton block :disabled="busy" @click="step = 3">Lanjut</AppButton>
      </div>

      <form v-else-if="step === 3" class="grid gap-4" @submit.prevent="saveContacts">
        <h1>Kontak utama</h1>
        <p class="text-muted">Tampil sebagai tombol cepat dan ikut tersimpan saat orang menekan Simpan Kontak.</p>
        <AppField label="WhatsApp"><AppInput v-model="form.whatsapp" inputmode="tel" placeholder="0812…" /></AppField>
        <AppField label="Telepon (jika beda)"><AppInput v-model="form.phone" inputmode="tel" /></AppField>
        <AppField label="Email"><AppInput v-model="form.email" type="email" /></AppField>
        <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>
        <AppButton type="submit" block :loading="busy">Lanjut</AppButton>
      </form>

      <div v-else class="grid gap-4">
        <TapZone :state="tapped ? 'ok' : 'waiting'" />
        <template v-if="tapped">
          <h1>Berhasil! Kartu Anda terbaca.</h1>
          <p class="text-muted">Begitulah orang lain akan membuka profil Anda.</p>
        </template>
        <template v-else>
          <h1>Coba tap kartu Anda</h1>
          <p class="text-muted" aria-live="polite">Tempelkan kartu ke bagian belakang ponsel Anda sendiri (atau scan QR-nya). Halaman ini menunggu tap pertama…</p>
        </template>
        <AppButton variant="secondary" block :href="card.url" target="_blank" rel="noopener"><Eye class="size-5" /> Buka profil publik</AppButton>
        <AppButton variant="secondary" block :to="`/cards/${token}/bio`">Atur tampilan bio link</AppButton>
        <AppButton block @click="finish">Selesai</AppButton>
      </div>
    </template>
  </div>
</template>
