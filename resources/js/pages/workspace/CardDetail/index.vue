<script setup>
// U-03 — ganti profil aktif (F-12), jeda/lanjutkan, lapor hilang & ditemukan (F-13), minta kartu pengganti, bagikan.
// Kartu digital: tanpa kode cetak & lapor hilang — dibagikan lewat QR layar & tautan.
// Kartu fisik: pemegang mengatur kode akun 6 angka agar kartu bisa dipakai masuk (kartu NFC + kode akun).
import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { Check, CircleAlert, Copy, Eye, KeyRound, Nfc, Pause, Pencil, Play, QrCode, RefreshCw, Share2, Smartphone } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import CardVisual from '@/components/cards/CardVisual/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { cardsApi } from '@/api/workspace'
import { useAction, useAsync } from '@/composables/useAsync'
import { dateTime, num, relative } from '@/lib/format'
import { useToastStore } from '@/stores/toast'

const route = useRoute()
const toast = useToastStore()
const { data: card } = useAsync(() => cardsApi.get(route.params.token))
const { busy, run } = useAction()
const label = ref('')
const confirm = ref(null) // 'lost' | 'paused' | 'replace'
const replaceNote = ref('')
const pin = ref({ value: '', confirm: '', error: '' })

watch(card, (c) => { if (c) label.value = c.label || '' }, { once: true })

async function patch(body, message) {
  const res = await run(() => cardsApi.update(route.params.token, body), message).catch(() => null)
  if (res) card.value = { ...card.value, ...res.data }
  confirm.value = null
}
async function requestReplacement() {
  const res = await run(() => cardsApi.requestReplacement(route.params.token, replaceNote.value || null), 'Permintaan kartu pengganti terkirim').catch(() => null)
  if (res) card.value = { ...card.value, ...res.data }
  confirm.value = null
}
async function saveLoginKey() {
  pin.value.error = ''
  if (!/^\d{6}$/.test(pin.value.value)) return (pin.value.error = 'Kode akun terdiri dari 6 angka.')
  if (pin.value.value !== pin.value.confirm) return (pin.value.error = 'Konfirmasi kode tidak sama.')
  const enabled = card.value.login_key.enabled
  const res = await run(() => cardsApi.setLoginKey(route.params.token, pin.value.value, pin.value.confirm), enabled ? 'Kode akun diganti' : 'Login dengan kartu aktif')
    .catch((e) => { pin.value.error = e.fields?.code?.[0] ?? e.message; return null })
  if (res) {
    card.value = { ...card.value, login_key: res.data }
    pin.value = { value: '', confirm: '', error: '' }
    confirm.value = null
  }
}
async function removeLoginKey() {
  const res = await run(() => cardsApi.removeLoginKey(route.params.token), 'Login dengan kartu dimatikan').catch(() => null)
  if (res) card.value = { ...card.value, login_key: res.data }
  confirm.value = null
}
const can = (s) => card.value?.allowed.includes(s)
async function copy() {
  try { await navigator.clipboard.writeText(card.value.url); toast.ok('Tautan disalin') } catch { toast.push(card.value.url) }
}
</script>

<template>
  <div v-if="!card" class="grid gap-4" aria-busy="true"><AppSkeleton class="!h-8 w-2/5" /><AppSkeleton class="!h-56" /></div>
  <template v-else>
    <PageHeader :title="card.label || 'Kartu ' + card.short_code" back="/cards" back-label="Kartu">
      <template #meta>
        <div class="flex flex-wrap gap-2">
          <AppBadge :tone="card.tone">{{ card.status_label }}</AppBadge>
          <AppBadge v-if="card.form === 'digital'" tone="info"><Smartphone class="size-3.5" /> Digital</AppBadge>
          <AppBadge v-else tone="outline">{{ card.short_code }}</AppBadge>
        </div>
      </template>
      <AppButton v-if="card.status === 'active'" :to="`/cards/${card.token}/share`"><Share2 class="size-5" /> Bagikan</AppButton>
      <AppButton v-if="card.status === 'active'" variant="secondary" :to="`/cards/${card.token}/qr`"><QrCode class="size-5" /> QR aman</AppButton>
      <AppButton variant="secondary" :href="card.url" target="_blank" rel="noopener"><Eye class="size-5" /> Lihat profil publik</AppButton>
    </PageHeader>

    <div v-if="card.status === 'lost'" class="flex gap-3 rounded-md border border-line-strong bg-surface-2 px-4 py-3 text-sm" role="alert">
      <CircleAlert class="size-5 flex-none text-danger" />
      <div class="grid gap-1">
        <span>Kartu ditandai hilang sejak {{ dateTime(card.lost_at) }}. Siapa pun yang men-tap akan melihat halaman netral.</span>
        <span v-for="(t, i) in card.lost_taps" :key="i">Di-tap {{ relative(t.at) }}{{ t.city ? ' · ' + t.city : '' }}</span>
        <span v-if="card.replacement_requested_at" class="text-muted">Kartu pengganti diminta {{ relative(card.replacement_requested_at) }}. Tim kami akan mencetaknya; kartu ini otomatis nonaktif saat pengganti diaktifkan.</span>
        <div v-else><AppButton variant="secondary" @click="confirm = 'replace'"><RefreshCw class="size-5" /> Minta kartu pengganti</AppButton></div>
      </div>
    </div>

    <AppPanel v-if="card.form === 'digital'" title="Tampilan kartu digital">
      <div class="grid gap-4">
        <p class="flex gap-3 text-sm text-muted">
          <Smartphone class="size-5 flex-none text-info" aria-hidden="true" />
          <span>Desainnya sama seperti kartu fisik, diisi dari bio kartu ini. Karena tanpa chip, QR di sisi belakang jadi pintu masuknya —
            unduh sebagai gambar untuk dikirim, dijadikan wallpaper, atau ditaruh di email signature.</span>
        </p>
        <p v-if="card.qr_rotation" class="flex gap-3 rounded-md border border-line-strong bg-surface-2 px-4 py-3 text-sm">
          <RefreshCw class="size-5 flex-none text-warn" aria-hidden="true" />
          <span>Paket Free: QR kartu digital ini berganti otomatis setiap {{ card.qr_rotation.days }} hari, dan QR lama tidak bisa dipakai lagi.
            QR sekarang berlaku sampai <b class="text-fg">{{ dateTime(card.qr_rotation.expires_at) }}</b> — unduh ulang setelah itu.
            Tautan profil &amp; @nama tetap sama. <RouterLink to="/billing">Upgrade ke Pro</RouterLink> untuk QR permanen.</span>
        </p>
        <CardVisual :token="card.token" :name="card.profile?.display_name || 'kartu'" editable />
      </div>
    </AppPanel>

    <div class="grid gap-4 md:grid-cols-2">
      <AppPanel title="Pengaturan kartu">
        <div class="grid gap-4">
          <AppField label="Nama kartu">
            <div class="flex gap-2">
              <AppInput v-model="label" maxlength="40" placeholder="mis. Kartu hitam" />
              <AppButton variant="secondary" :disabled="busy" @click="patch({ label }, 'Nama kartu disimpan')">Simpan</AppButton>
            </div>
          </AppField>
          <div v-if="card.profile" class="grid gap-2 rounded-md border border-line bg-surface-2 p-4">
            <span class="label">Bio link kartu ini</span>
            <span class="font-medium text-fg">{{ card.profile.display_name }}</span>
            <span class="text-sm text-muted">Setiap kartu punya bio sendiri. Ubah kapan saja — {{ card.form === 'digital' ? 'tautan & QR tetap sama' : 'URL di chip & QR tetap sama, tidak perlu cetak ulang' }}.</span>
            <div><AppButton :to="`/cards/${card.token}/bio`"><Pencil class="size-5" /> Atur bio link</AppButton></div>
          </div>
          <div class="flex flex-wrap gap-2">
            <AppButton v-if="can('paused')" variant="secondary" :disabled="busy" @click="confirm = 'paused'"><Pause class="size-5" /> Jeda profil</AppButton>
            <AppButton v-if="card.status === 'paused' && can('active')" :disabled="busy" @click="patch({ status: 'active' }, 'Profil aktif kembali')"><Play class="size-5" /> Lanjutkan</AppButton>
            <AppButton v-if="can('lost')" variant="ghost" :disabled="busy" @click="confirm = 'lost'"><CircleAlert class="size-5" /> Laporkan hilang</AppButton>
            <AppButton v-if="card.status === 'lost' && can('active')" :disabled="busy" @click="patch({ status: 'active' }, 'Kartu aktif kembali')"><Check class="size-5" /> Sudah ditemukan</AppButton>
          </div>
        </div>
      </AppPanel>

      <AppPanel title="30 hari terakhir">
        <div class="grid gap-4">
          <div class="grid grid-cols-3 gap-4">
            <div v-for="[k, l] in [['taps', 'Tap'], ['visitors', 'Unik'], ['saved', 'Simpan']]" :key="k" class="grid gap-2">
              <span class="label">{{ l }}</span><span class="num text-[28px] leading-none">{{ num(card.stats_30[k]) }}</span>
            </div>
          </div>
          <p class="text-sm text-muted">NFC {{ num(card.stats_30.nfc) }} · QR {{ num(card.stats_30.qr) }} · lainnya {{ num(card.stats_30.other) }}</p>
          <div class="flex items-center gap-3">
            <img :src="card.qr_svg_url" alt="QR kartu" width="96" height="96" class="flex-none rounded-sm bg-bone-0 p-1" />
            <div class="grid min-w-0 gap-2">
              <span class="truncate font-mono text-sm">{{ card.url }}</span>
              <AppButton variant="ghost" @click="copy"><Copy class="size-5" /> Salin tautan</AppButton>
            </div>
          </div>
        </div>
      </AppPanel>
    </div>

    <AppPanel v-if="card.login_key" title="Masuk dengan kartu ini">
      <div class="grid gap-4">
        <p class="flex gap-3 text-sm text-muted">
          <Nfc class="size-5 flex-none text-info" aria-hidden="true" />
          <span>Tempelkan kartu ini ke ponsel (Android atau iPhone), ketuk <b class="text-fg">Masuk sebagai pemilik</b>, lalu ketik kode akun.
            Di Chrome Android bisa juga dari halaman masuk → <b class="text-fg">Masuk dengan kartu NFC</b>.
            Kartu saja tidak cukup — siapa pun yang men-tap kartu Anda tetap butuh kode akun.</span>
        </p>
        <p v-if="!card.login_key.available" class="text-sm text-muted">Belum tersedia: kartu harus dalam keadaan aktif.</p>
        <template v-else>
          <div class="flex flex-wrap items-center gap-2 text-sm">
            <AppBadge :tone="card.login_key.enabled ? 'ok' : 'outline'">{{ card.login_key.enabled ? 'Aktif' : 'Belum aktif' }}</AppBadge>
            <span v-if="card.login_key.last_used_at" class="text-muted">Terakhir dipakai {{ relative(card.login_key.last_used_at) }}</span>
            <span v-if="card.login_key.locked_until" class="text-danger">Terkunci sampai {{ dateTime(card.login_key.locked_until) }} karena kode salah berulang kali</span>
          </div>
          <div class="flex flex-wrap gap-2">
            <AppButton :variant="card.login_key.enabled ? 'secondary' : 'primary'" :disabled="busy" @click="confirm = 'pin'">
              <KeyRound class="size-5" /> {{ card.login_key.enabled ? 'Ganti kode akun' : 'Aktifkan & atur kode akun' }}
            </AppButton>
            <AppButton v-if="card.login_key.enabled" variant="ghost" :disabled="busy" @click="confirm = 'unpin'">Matikan</AppButton>
          </div>
        </template>
      </div>
    </AppPanel>

    <AppModal v-if="confirm === 'pin'" :title="card.login_key.enabled ? 'Ganti kode akun' : 'Atur kode akun'" @close="confirm = null">
      <form id="pin-form" class="grid gap-4" novalidate @submit.prevent="saveLoginKey">
        <p class="text-sm text-muted">6 angka, hindari angka kembar atau berurutan (mis. 111111, 123456). Salah 5 kali, login kartu terkunci 15 menit.</p>
        <AppField label="Kode akun baru" :error="pin.error">
          <AppInput v-model.trim="pin.value" code type="password" inputmode="numeric" maxlength="6" autocomplete="new-password" autofocus :invalid="!!pin.error" />
        </AppField>
        <AppField label="Ulangi kode">
          <AppInput v-model.trim="pin.confirm" code type="password" inputmode="numeric" maxlength="6" autocomplete="new-password" />
        </AppField>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="confirm = null">Batal</AppButton>
        <AppButton type="submit" form="pin-form" :loading="busy">Simpan kode</AppButton>
      </template>
    </AppModal>
    <AppModal v-if="confirm === 'unpin'" title="Matikan login dengan kartu?" @close="confirm = null">
      <p>Kartu ini tidak bisa dipakai masuk lagi sampai Anda mengatur kode akun baru. Fungsi tap & bio link tetap berjalan.</p>
      <template #footer>
        <AppButton variant="secondary" @click="confirm = null">Batal</AppButton>
        <AppButton variant="danger" :loading="busy" @click="removeLoginKey">Matikan</AppButton>
      </template>
    </AppModal>
    <AppModal v-if="confirm === 'lost'" title="Laporkan kartu hilang?" @close="confirm = null">
      <p>Tap berikutnya akan menampilkan halaman netral, dan Anda akan diberi tahu waktu & kota tap tersebut. Anda bisa mengaktifkannya lagi jika kartu ditemukan.</p>
      <template #footer>
        <AppButton variant="secondary" @click="confirm = null">Batal</AppButton>
        <AppButton variant="danger" :loading="busy" @click="patch({ status: 'lost' }, 'Kartu ditandai hilang')">Ya, tandai hilang</AppButton>
      </template>
    </AppModal>
    <AppModal v-if="confirm === 'replace'" title="Minta kartu pengganti?" @close="confirm = null">
      <p>Kami cetak kartu baru dengan profil yang sama. Begitu kartu baru diaktifkan, kartu yang hilang ini tidak berlaku lagi.</p>
      <AppField label="Catatan (opsional)" hint="mis. alamat kirim berbeda."><AppInput v-model="replaceNote" maxlength="200" /></AppField>
      <template #footer>
        <AppButton variant="secondary" @click="confirm = null">Batal</AppButton>
        <AppButton :loading="busy" @click="requestReplacement">Kirim permintaan</AppButton>
      </template>
    </AppModal>
    <AppModal v-if="confirm === 'paused'" title="Jeda profil?" @close="confirm = null">
      <p>Selama dijeda, orang yang men-tap kartu ini melihat halaman "Profil sedang tidak aktif".</p>
      <template #footer>
        <AppButton variant="secondary" @click="confirm = null">Batal</AppButton>
        <AppButton :loading="busy" @click="patch({ status: 'paused' }, 'Profil dijeda')">Jeda</AppButton>
      </template>
    </AppModal>
  </template>
</template>
