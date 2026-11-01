<script setup>
// U-15 — akun, tautan @handle, preferensi notifikasi (NT-02), paket & pemakaian, izin (RL-09), ekspor & hapus akun (PV-02).
import { reactive, ref, watch } from 'vue'
import { Download, ShieldCheck } from 'lucide-vue-next'
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
import { meApi } from '@/api/workspace'
import { config } from '@/config'
import { useAction, useAsync } from '@/composables/useAsync'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const { data: settings } = useAsync(() => meApi.settings())
const { busy, run } = useAction()

const user = auth.me
const form = reactive({ name: user.name, phone: user.phone || '', handle: user.handle || '', locale: user.locale })
const prefs = reactive({})
const errors = ref({})
const deleting = ref(false)
const confirmEmail = ref('')
const deleteError = ref('')
const showPerms = ref(false)
const USAGE = { cards_active: 'Kartu aktif', profiles_per_user: 'Profil', connections: 'Koneksi' }

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

    <AppPanel v-if="settings" title="Paket">
      <template #actions><AppBadge tone="outline">{{ settings.plan.name }}</AppBadge><AppButton variant="secondary" to="/billing">Kelola paket</AppButton></template>
      <div class="grid gap-4">
        <AppMeter v-for="(label, k) in USAGE" :key="k" :label="label" :value="settings.plan.usage[k]" :max="settings.plan.limits[k]" />
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
        <p class="text-sm text-muted">Menghapus akun mencabut semua kartu. Akun disimpan 30 hari sebelum dihapus permanen.</p>
        <div><AppButton variant="danger" @click="deleting = true">Hapus akun</AppButton></div>
      </div>
    </AppPanel>
  </div>

  <AppModal v-if="deleting" title="Hapus akun?" @close="deleting = false">
    <p>Semua kartu Anda akan berhenti berfungsi. Ketik email <strong>{{ user.email }}</strong> untuk konfirmasi.</p>
    <AppInput v-model="confirmEmail" type="email" aria-label="Konfirmasi email" />
    <p v-if="deleteError" class="text-sm text-danger" role="alert">{{ deleteError }}</p>
    <template #footer>
      <AppButton variant="secondary" @click="deleting = false">Batal</AppButton>
      <AppButton variant="danger" :disabled="confirmEmail !== user.email" @click="destroy">Hapus akun permanen</AppButton>
    </template>
  </AppModal>
</template>
