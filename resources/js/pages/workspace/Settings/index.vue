<script setup>
// U-15 — akun, tautan @handle, preferensi notifikasi (NT-02), paket & pemakaian, izin (RL-09), ekspor & hapus akun (PV-02).
import { computed, reactive, ref, watch } from 'vue'
import { CircleCheck, CreditCard, Download, LogOut, Moon, Plus, ShieldCheck, Smartphone, Trash2 } from 'lucide-vue-next'
import InstallApp from '@/components/common/InstallApp/index.vue'
import { useTheme } from '@/composables/useTheme'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppMeter from '@/components/ui/AppMeter/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import AppTable from '@/components/ui/AppTable/index.vue'
import AppToggle from '@/components/ui/AppToggle/index.vue'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { billingApi, meApi } from '@/api/workspace'
import { config } from '@/config'
import { useAction, useAsync } from '@/composables/useAsync'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { useRoute, useRouter } from 'vue-router'

const auth = useAuthStore()
const { toggle: toggleTheme } = useTheme()
const { data: settings } = useAsync(() => meApi.settings())
const { busy, run } = useAction()

const user = auth.me
const route = useRoute()
const router = useRouter()
const toast = useToastStore()
// hasil "Hubungkan Google" (GoogleController?link=1) kembali ke sini dengan ?google=
const GOOGLE_RESULT = {
  linked: 'Akun Google terhubung. Sekarang Anda juga bisa masuk dengan Google.',
  taken: 'Akun Google itu sudah terhubung ke akun TapCard lain.',
  failed: 'Gagal menghubungkan Google. Coba lagi.',
  unverified: 'Email Google Anda belum terverifikasi.',
}
if (route.query.google) {
  const msg = GOOGLE_RESULT[route.query.google]
  if (msg) (route.query.google === 'linked' ? toast.ok(msg) : toast.push(msg))
  router.replace({ query: {} })
}
const googleLinkUrl = `/auth/google/redirect?link=1&redirect=${encodeURIComponent('/settings')}`
const form = reactive({ name: user.name, phone: user.phone || '', handle: user.handle || '', locale: user.locale })
const prefs = reactive({})
const errors = ref({})
const deleting = ref(false)
const confirmEmail = ref('')
const deleteError = ref('')
const showPerms = ref(false)
const USAGE = { cards_active: 'Kartu fisik aktif', profiles_per_user: 'Profil', connections: 'Koneksi' }
const rupiah = (n) => 'Rp' + Number(n).toLocaleString('id-ID')
const num = (n) => Number(n).toLocaleString('id-ID')
const digital = computed(() => settings.value?.digital_card ?? null)
const deletion = computed(() => settings.value?.deletion ?? null)
const topping = ref(false)

watch(settings, (s) => s && Object.assign(prefs, structuredClone(s.prefs)), { immediate: true })

async function save(body, message) {
  errors.value = {}
  try {
    await run(() => meApi.update(body), message)
    await auth.load(true)
  } catch (e) {
    errors.value = e.fields || {}
  }
}
/** Beli tambahan tap kartu digital → halaman bayar (simulator = rute SPA, gateway asli = halaman luar). */
async function topup() {
  topping.value = true
  try {
    const res = await billingApi.tapTopup()
    const u = new URL(res.data.payment_url, window.location.href)
    if (u.origin === window.location.origin) router.push(u.pathname + u.search)
    else window.location.assign(res.data.payment_url)
  } catch (e) {
    toast.push(e.message)
  } finally {
    topping.value = false
  }
}
async function destroy() {
  deleteError.value = ''
  try {
    await meApi.destroy(confirmEmail.value)
    window.location.href = '/login'
  } catch (e) {
    deleteError.value = e.message
  }
}
</script>

<template>
  <PageHeader eyebrow="Akun" title="Pengaturan" />

  <div class="grid gap-4 md:grid-cols-2">
    <AppPanel title="Akun">
      <form class="grid gap-4" @submit.prevent="save(form, 'Akun disimpan')">
        <AppField label="Nama"><AppInput v-model="form.name" maxlength="120" /></AppField>
        <AppField label="Email"><AppInput :model-value="user.email" disabled /></AppField>
        <AppField label="Telepon"><AppInput v-model="form.phone" inputmode="tel" /></AppField>
        <AppField label="Nama pengguna (tautan profil)" :error="errors.handle?.[0]" :hint="form.handle ? `${config.resolverUrl}/@${form.handle}` : ''">
          <AppInput v-model="form.handle" maxlength="30" placeholder="namaanda" autocapitalize="off" :invalid="!!errors.handle" />
        </AppField>
        <AppField label="Bahasa"><AppSelect v-model="form.locale" :options="[{ value: 'id', label: 'Indonesia' }, { value: 'en', label: 'English' }]" /></AppField>
        <div><AppButton type="submit" :loading="busy">Simpan</AppButton></div>
      </form>
    </AppPanel>

    <AppPanel title="Cara masuk">
      <div class="grid gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="grid gap-1">
            <span class="font-medium">Google</span>
            <span class="text-sm text-muted">{{ user.google_linked ? 'Terhubung — bisa masuk dengan tombol Google.' : 'Belum terhubung. Hubungkan agar bisa masuk tanpa kartu.' }}</span>
          </div>
          <AppBadge v-if="user.google_linked" tone="ok">Terhubung</AppBadge>
          <AppButton v-else-if="config.googleEnabled" variant="secondary" :href="googleLinkUrl">Hubungkan Google</AppButton>
          <span v-else class="text-sm text-muted">Belum tersedia</span>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-4">
          <div class="grid gap-1">
            <span class="font-medium">Kartu NFC + kode akun</span>
            <span class="text-sm text-muted">Tempelkan kartu fisik, lalu ketik kode akun 6 angka. Atur atau ganti kodenya di detail kartu.</span>
          </div>
          <AppButton variant="secondary" to="/cards"><CreditCard class="size-5" /> Kartu saya</AppButton>
        </div>
      </div>
    </AppPanel>

    <AppPanel title="Notifikasi">
      <AppSkeleton v-if="!settings" class="!h-40" />
      <div v-else class="grid gap-4">
        <!-- Ponsel: tiap kejadian jadi satu blok dengan dua saklar bersebelahan (tabel 3 kolom terlalu sempit) -->
        <ul class="grid gap-3 sm:hidden">
          <li v-for="(label, k) in settings.notification_types" :key="k" class="grid gap-1 rounded-md border border-line p-3">
            <span class="text-sm font-medium">{{ label }}</span>
            <div class="flex flex-wrap gap-x-6">
              <AppToggle v-model="prefs[k].inapp" label="Di aplikasi" />
              <AppToggle v-model="prefs[k].email" label="Email" />
            </div>
          </li>
        </ul>
        <div class="hidden sm:block">
          <AppTable>
            <thead><tr><th>Kejadian</th><th>Di aplikasi</th><th>Email</th></tr></thead>
            <tbody>
              <tr v-for="(label, k) in settings.notification_types" :key="k">
                <td>{{ label }}</td>
                <td><AppToggle v-model="prefs[k].inapp" :aria-label="`${label} di aplikasi`" /></td>
                <td><AppToggle v-model="prefs[k].email" :aria-label="`${label} via email`" /></td>
              </tr>
            </tbody>
          </AppTable>
        </div>
        <div><AppButton variant="secondary" :loading="busy" @click="save({ notification_prefs: prefs }, 'Preferensi notifikasi disimpan')">Simpan preferensi</AppButton></div>
      </div>
    </AppPanel>

    <!-- Paket kartu digital: terpisah dari paket workspace — kuota tap kumulatif + 1 bio link -->
    <AppPanel v-if="digital" title="Paket kartu digital">
      <template #actions><AppBadge :tone="digital.taps.exhausted ? 'danger' : 'outline'">{{ digital.taps.exhausted ? 'Kuota habis' : 'Aktif' }}</AppBadge></template>
      <div class="grid gap-4">
        <AppMeter label="Tap terpakai" :value="digital.taps.used" :max="digital.taps.quota">{{ num(digital.taps.used) }} / {{ num(digital.taps.quota) }}</AppMeter>
        <p v-if="digital.taps.exhausted" class="rounded-md border border-danger p-3 text-sm text-danger" role="alert">
          Kuota tap habis — profil kartu digital Anda tidak bisa dibuka pengunjung sampai Anda menambah tap.
        </p>
        <p v-else class="text-sm text-muted">Sisa {{ num(digital.taps.remaining) }} tap. Kuota tidak di-reset bulanan; kunjungan ulang orang yang sama dalam 30 menit dan bot tidak dihitung.</p>
        <AppMeter label="Bio link" :value="digital.bio_pages.used" :max="digital.bio_pages.limit" />
        <div class="flex flex-wrap items-center gap-3 border-t border-line pt-4">
          <AppButton :loading="topping" @click="topup"><Plus class="size-5" /> Tambah {{ num(digital.topup.taps) }} tap — {{ rupiah(digital.topup.price) }}</AppButton>
          <AppButton variant="ghost" :to="`/cards/${digital.token}`"><Smartphone class="size-5" /> Kartu digital</AppButton>
        </div>
        <p v-if="digital.open_invoice" class="text-sm text-muted">
          Ada pembayaran tambahan tap yang belum selesai.
          <RouterLink class="font-semibold text-accent underline" :to="`/billing/invoices/${digital.open_invoice}`">Lanjutkan bayar</RouterLink>
        </p>
      </div>
    </AppPanel>

    <AppPanel v-if="settings" title="Paket kartu fisik">
      <template #actions><AppBadge tone="outline">{{ settings.plan.name }}</AppBadge><AppButton variant="secondary" to="/billing">Kelola paket</AppButton></template>
      <div class="grid gap-4">
        <AppMeter v-for="(label, k) in USAGE" :key="k" :label="label" :value="settings.plan.usage[k]" :max="settings.plan.limits[k]" />
        <p class="text-sm text-muted">Kartu fisik (NFC) tanpa batas tap dan bisa punya sampai 3 bio link per kartu.</p>
        <div><AppButton variant="ghost" @click="showPerms = !showPerms"><ShieldCheck class="size-5" /> Apa yang bisa saya lakukan?</AppButton></div>
        <ul v-if="showPerms" class="font-mono text-sm">
          <li v-for="p in settings.permissions" :key="p" class="border-b border-line py-2 last:border-b-0">{{ p }}</li>
        </ul>
      </div>
    </AppPanel>

    <AppPanel title="Privasi & data">
      <div class="grid gap-4">
        <p class="text-sm text-muted">Unduh profil, kartu, koneksi, dan riwayat tap 24 bulan dalam format JSON.</p>
        <div><AppButton variant="secondary" :href="meApi.exportUrl"><Download class="size-5" /> Unduh data saya</AppButton></div>
        <hr class="border-line" />
        <p class="text-sm text-muted">Menghapus akun mencabut semua kartu. Data pribadi disimpan tersembunyi 30 hari, lalu dihapus permanen. Mendaftar lagi nanti = akun baru yang kosong.</p>
        <div><AppButton variant="danger" @click="deleting = true"><Trash2 class="size-5" /> Hapus akun</AppButton></div>
      </div>
    </AppPanel>
  </div>

  <!-- Ponsel: sidebar (tempat menu akun) tersembunyi, jadi tema, pasang aplikasi, dan Keluar ada di sini -->
  <AppPanel title="Sesi" class="lg:hidden">
    <div class="grid gap-3 sm:flex sm:flex-wrap">
      <AppButton variant="secondary" @click="toggleTheme"><Moon class="size-5" /> Ganti tema</AppButton>
      <InstallApp link-class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md border border-line-strong px-4 font-semibold hover:bg-surface-2" />
      <AppButton variant="danger" @click="auth.askLogout()"><LogOut class="size-5" /> Keluar</AppButton>
    </div>
  </AppPanel>

  <AppModal v-if="deleting" title="Hapus akun?" @close="deleting = false">
    <div class="grid gap-4">
      <div class="grid gap-2 rounded-md border border-danger p-3">
        <p class="font-semibold text-danger">Langsung saat ini</p>
        <ul class="grid list-disc gap-1 pl-5 text-sm">
          <li><strong>{{ deletion?.cards ?? 0 }} kartu</strong> dicabut — tap berikutnya menampilkan "kartu tidak berlaku".</li>
          <li>Langganan berbayar dihentikan, tagihan yang belum dibayar dibatalkan.</li>
          <li>Anda keluar dari semua perangkat dan semua workspace.</li>
          <li>Email &amp; akun Google dilepas dari akun ini.</li>
        </ul>
      </div>
      <div class="grid gap-2 rounded-md border border-line p-3">
        <p class="font-semibold">Setelah {{ deletion?.retention_days ?? 30 }} hari — dihapus permanen</p>
        <ul class="grid list-disc gap-1 pl-5 text-sm text-muted">
          <li>{{ deletion?.profiles ?? 0 }} profil &amp; bio link beserta foto</li>
          <li>{{ deletion?.connections ?? 0 }} koneksi, catatan, pengingat, dan isian formulir</li>
          <li>Desain kartu, impor/ekspor, notifikasi</li>
        </ul>
      </div>
      <div class="grid gap-2 rounded-md border border-line p-3">
        <p class="font-semibold">Tetap disimpan sebagai riwayat (tanpa identitas Anda)</p>
        <ul class="grid list-disc gap-1 pl-5 text-sm text-muted">
          <li>Statistik tap yang dianonimkan</li>
          <li>Invoice, pesanan, dan pembayaran (catatan keuangan)</li>
          <li>Log audit</li>
        </ul>
      </div>
      <p class="flex gap-2 text-sm"><CircleCheck class="size-5 shrink-0 text-accent" /> <span>Bila nanti mendaftar lagi dengan email yang sama, Anda mendapat akun <strong>baru dan kosong</strong> — akun ini tidak bisa dipulihkan atau dilanjutkan.</span></p>
      <AppField :label="`Ketik ${user.email} untuk konfirmasi`" :error="deleteError">
        <AppInput v-model="confirmEmail" type="email" autocomplete="off" aria-label="Konfirmasi email" :invalid="!!deleteError" />
      </AppField>
    </div>
    <template #footer>
      <AppButton variant="secondary" @click="deleting = false">Batal</AppButton>
      <AppButton variant="danger" :disabled="confirmEmail !== user.email" @click="destroy">Hapus akun</AppButton>
    </template>
  </AppModal>
</template>
