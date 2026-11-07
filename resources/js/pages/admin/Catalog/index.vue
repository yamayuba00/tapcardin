<script setup>
// Katalog — harga lanyard (lebar tali: cetak 1/2 sisi, minimal pesan; pengait) dan jenis kontak bio link
// (aktif/nonaktif, label, urutan), dan add-on (harga, status, jumlah workspace yang mendaftar minat).
// Perubahan harga langsung dipakai editor & katalog lanyard pelanggan.
import { computed, reactive, ref, watch } from 'vue'
import { Plus, Save } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import AppTextarea from '@/components/ui/AppTextarea/index.vue'
import AppToggle from '@/components/ui/AppToggle/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { useAction, useAsync } from '@/composables/useAsync'

const { data: c } = useAsync(() => adminApi.catalog())
const { busy, run } = useAction()
// salinan yang bisa diedit per baris; disegarkan setiap respons server
const widths = ref([])
const hardware = ref([])
const types = ref([])
const addons = ref([])
const products = ref([])
const shipping = ref([])
watch(c, (v) => {
  if (!v) return
  products.value = v.card_products.map((p) => ({ ...p }))
  shipping.value = v.shipping_methods.map((m) => ({ ...m }))
  addons.value = v.addons.map((a) => ({ ...a, featuresText: (a.features ?? []).join('\n'), price_monthly: a.price_monthly ?? '' }))
  widths.value = v.lanyard_widths.map((w) => ({ ...w }))
  hardware.value = v.lanyard_hardware.map((h) => ({ ...h }))
  types.value = v.contact_types.map((t) => ({ ...t }))
}, { immediate: true })

const groupFilter = ref('')
const groups = computed(() => [...new Set(types.value.map((t) => t.group))])
const shownTypes = computed(() => types.value.filter((t) => !groupFilter.value || t.group === groupFilter.value))

const ints = (o, keys) => Object.fromEntries(keys.map((k) => [k, Number(o[k])]))
async function saveWidth(w) {
  const res = await run(() => adminApi.saveLanyardWidth(w.public_id, { label: w.label, is_active: w.is_active, ...ints(w, ['price_single', 'price_double', 'min_qty', 'sort']) }), `Harga ${w.label} disimpan.`).catch(() => null)
  if (res) c.value = res.data
}
async function saveHardware(h) {
  const res = await run(() => adminApi.saveLanyardHardware(h.public_id, { name: h.name, description: h.description || null, is_active: h.is_active, ...ints(h, ['price', 'sort']) }), `${h.name} disimpan.`).catch(() => null)
  if (res) c.value = res.data
}
const fresh = reactive({ name: '', price: 0 })
async function addHardware() {
  const res = await run(() => adminApi.saveLanyardHardware(null, { name: fresh.name, price: Number(fresh.price) }), 'Pengait ditambahkan.').catch(() => null)
  if (res) { c.value = res.data; fresh.name = ''; fresh.price = 0 }
}
async function saveProduct(p) {
  const res = await run(() => adminApi.saveCardProduct(p.public_id, { name: p.name, description: p.description || null, is_active: p.is_active, ...ints(p, ['price', 'min_qty', 'sort']) }), `${p.name} disimpan.`).catch(() => null)
  if (res) c.value = res.data
}
async function saveShipping(m) {
  const res = await run(() => adminApi.saveShipping(m.public_id, { name: m.name, eta: m.eta || null, is_active: m.is_active, needs_address: m.needs_address, ...ints(m, ['price', 'sort']) }), `${m.name} disimpan.`).catch(() => null)
  if (res) c.value = res.data
}
const ADDON_STATUS = [{ value: 'coming_soon', label: 'Segera hadir (daftar minat)' }, { value: 'available', label: 'Tersedia' }, { value: 'retired', label: 'Dihentikan' }]
async function saveAddon(a) {
  const res = await run(() => adminApi.updateAddon(a.public_id, {
    name: a.name, description: a.description, status: a.status, price_monthly: a.price_monthly === '' ? null : Number(a.price_monthly),
    features: a.featuresText.split('\n').map((f) => f.trim()).filter(Boolean),
  }), `${a.name} disimpan.`).catch(() => null)
  if (res) c.value = res.data
}
async function saveType(t, patch) {
  const res = await run(() => adminApi.updateContactType(t.public_id, patch)).catch(() => null)
  if (res) c.value = res.data
}
</script>

<template>
  <PageHeader eyebrow="Bisnis" title="Katalog" />
  <AppSkeleton v-if="!c" class="!h-60" />
  <template v-else>
    <AppPanel title="Kartu fisik · produk" flush>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[760px] text-sm table-stack">
          <thead>
            <tr class="text-left text-muted">
              <th class="px-4 py-2 font-medium">Nama</th><th class="px-4 py-2 font-medium">Deskripsi</th><th class="px-4 py-2 font-medium">Chip</th>
              <th class="px-4 py-2 font-medium">Harga / kartu (Rp)</th><th class="px-4 py-2 font-medium">Min. pesan</th><th class="px-4 py-2 font-medium">Aktif</th><th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in products" :key="p.public_id" class="border-t border-line">
              <td data-label="Nama" class="px-4 py-2"><AppInput v-model="p.name" maxlength="80" :aria-label="`Nama ${p.key}`" /></td>
              <td data-label="Deskripsi" class="px-4 py-2"><AppInput v-model="p.description" maxlength="200" :aria-label="`Deskripsi ${p.name}`" /></td>
              <td data-label="Chip" class="px-4 py-2 font-mono uppercase">{{ p.chip_type }}</td>
              <td data-label="Harga / kartu (Rp)" class="px-4 py-2"><AppInput v-model="p.price" type="number" min="0" class="max-w-32" :aria-label="`Harga ${p.name}`" /></td>
              <td data-label="Min. pesan" class="px-4 py-2"><AppInput v-model="p.min_qty" type="number" min="1" class="max-w-24" :aria-label="`Minimal pesan ${p.name}`" /></td>
              <td data-label="Aktif" class="px-4 py-2"><AppToggle v-model="p.is_active" :label="p.is_active ? 'Ya' : 'Tidak'" /></td>
              <td class="px-4 py-2 text-right"><AppButton variant="secondary" :loading="busy" @click="saveProduct(p)"><Save class="size-4" /> Simpan</AppButton></td>
            </tr>
          </tbody>
        </table>
      </div>
    </AppPanel>

    <AppPanel title="Pengiriman" flush>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[760px] text-sm table-stack">
          <thead>
            <tr class="text-left text-muted">
              <th class="px-4 py-2 font-medium">Nama</th><th class="px-4 py-2 font-medium">Estimasi</th><th class="px-4 py-2 font-medium">Ongkir / pesanan (Rp)</th>
              <th class="px-4 py-2 font-medium">Perlu alamat</th><th class="px-4 py-2 font-medium">Aktif</th><th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in shipping" :key="m.public_id" class="border-t border-line">
              <td data-label="Nama" class="px-4 py-2"><AppInput v-model="m.name" maxlength="80" :aria-label="`Nama ${m.key}`" /></td>
              <td data-label="Estimasi" class="px-4 py-2"><AppInput v-model="m.eta" maxlength="60" :aria-label="`Estimasi ${m.name}`" /></td>
              <td data-label="Ongkir / pesanan (Rp)" class="px-4 py-2"><AppInput v-model="m.price" type="number" min="0" class="max-w-32" :aria-label="`Ongkir ${m.name}`" /></td>
              <td data-label="Perlu alamat" class="px-4 py-2"><AppToggle v-model="m.needs_address" :label="m.needs_address ? 'Ya' : 'Tidak'" /></td>
              <td data-label="Aktif" class="px-4 py-2"><AppToggle v-model="m.is_active" :label="m.is_active ? 'Ya' : 'Tidak'" /></td>
              <td class="px-4 py-2 text-right"><AppButton variant="secondary" :loading="busy" @click="saveShipping(m)"><Save class="size-4" /> Simpan</AppButton></td>
            </tr>
          </tbody>
        </table>
      </div>
    </AppPanel>

    <AppPanel title="Lanyard · lebar tali" flush>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[760px] text-sm table-stack">
          <thead>
            <tr class="text-left text-muted">
              <th class="px-4 py-2 font-medium">Lebar</th><th class="px-4 py-2 font-medium">Label</th>
              <th class="px-4 py-2 font-medium">Cetak 1 sisi (Rp)</th><th class="px-4 py-2 font-medium">Cetak 2 sisi (Rp)</th>
              <th class="px-4 py-2 font-medium">Min. pesan</th><th class="px-4 py-2 font-medium">Aktif</th><th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="w in widths" :key="w.public_id" class="border-t border-line">
              <td data-label="Lebar" class="px-4 py-2 font-mono">{{ w.width_mm }} mm</td>
              <td data-label="Label" class="px-4 py-2"><AppInput v-model="w.label" maxlength="60" :aria-label="`Label ${w.width_mm} mm`" /></td>
              <td data-label="Cetak 1 sisi (Rp)" class="px-4 py-2"><AppInput v-model="w.price_single" type="number" min="0" :aria-label="`Harga 1 sisi ${w.width_mm} mm`" /></td>
              <td data-label="Cetak 2 sisi (Rp)" class="px-4 py-2"><AppInput v-model="w.price_double" type="number" min="0" :aria-label="`Harga 2 sisi ${w.width_mm} mm`" /></td>
              <td data-label="Min. pesan" class="px-4 py-2"><AppInput v-model="w.min_qty" type="number" min="1" class="max-w-24" :aria-label="`Minimal pesan ${w.width_mm} mm`" /></td>
              <td data-label="Aktif" class="px-4 py-2"><AppToggle v-model="w.is_active" :label="w.is_active ? 'Ya' : 'Tidak'" /></td>
              <td class="px-4 py-2 text-right"><AppButton variant="secondary" :loading="busy" @click="saveWidth(w)"><Save class="size-4" /> Simpan</AppButton></td>
            </tr>
          </tbody>
        </table>
      </div>
    </AppPanel>

    <AppPanel title="Lanyard · pengait & aksesori" flush>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] text-sm table-stack">
          <thead>
            <tr class="text-left text-muted">
              <th class="px-4 py-2 font-medium">Nama</th><th class="px-4 py-2 font-medium">Deskripsi</th>
              <th class="px-4 py-2 font-medium">Harga (Rp)</th><th class="px-4 py-2 font-medium">Aktif</th><th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="h in hardware" :key="h.public_id" class="border-t border-line">
              <td data-label="Nama" class="px-4 py-2"><AppInput v-model="h.name" maxlength="60" :aria-label="`Nama ${h.key}`" /></td>
              <td data-label="Deskripsi" class="px-4 py-2"><AppInput v-model="h.description" maxlength="200" :aria-label="`Deskripsi ${h.name}`" /></td>
              <td data-label="Harga (Rp)" class="px-4 py-2"><AppInput v-model="h.price" type="number" min="0" class="max-w-32" :aria-label="`Harga ${h.name}`" /></td>
              <td data-label="Aktif" class="px-4 py-2"><AppToggle v-model="h.is_active" :label="h.is_active ? 'Ya' : 'Tidak'" /></td>
              <td class="px-4 py-2 text-right"><AppButton variant="secondary" :loading="busy" @click="saveHardware(h)"><Save class="size-4" /> Simpan</AppButton></td>
            </tr>
            <tr class="border-t border-line">
              <td class="px-4 py-2"><AppInput v-model="fresh.name" maxlength="60" placeholder="Pengait baru" aria-label="Nama pengait baru" /></td>
              <td class="px-4 py-2 text-muted">—</td>
              <td class="px-4 py-2"><AppInput v-model="fresh.price" type="number" min="0" class="max-w-32" aria-label="Harga pengait baru" /></td>
              <td></td>
              <td class="px-4 py-2 text-right"><AppButton :disabled="!fresh.name.trim()" :loading="busy" @click="addHardware"><Plus class="size-4" /> Tambah</AppButton></td>
            </tr>
          </tbody>
        </table>
      </div>
    </AppPanel>

    <AppPanel title="Add-on">
      <div class="grid gap-6">
        <article v-for="a in addons" :key="a.public_id" class="grid gap-4 border-b border-line pb-6 last:border-b-0 last:pb-0">
          <div class="flex flex-wrap items-center gap-3">
            <span class="font-mono text-sm text-muted">{{ a.key }}</span>
            <AppBadge :tone="a.status === 'available' ? 'ok' : a.status === 'retired' ? 'muted' : 'info'">{{ ADDON_STATUS.find((s) => s.value === a.status)?.label }}</AppBadge>
            <span class="ml-auto text-sm text-muted"><span class="num text-fg">{{ a.interests }}</span> workspace mendaftar minat</span>
          </div>
          <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_180px_220px]">
            <AppInput v-model="a.name" maxlength="80" :aria-label="`Nama add-on ${a.key}`" />
            <AppInput v-model="a.price_monthly" type="number" min="0" placeholder="Harga / bulan" :aria-label="`Harga bulanan ${a.key}`" />
            <AppSelect v-model="a.status" :options="ADDON_STATUS" :aria-label="`Status ${a.key}`" />
          </div>
          <AppTextarea v-model="a.description" maxlength="300" rows="2" :aria-label="`Deskripsi ${a.key}`" />
          <AppTextarea v-model="a.featuresText" rows="4" :aria-label="`Manfaat ${a.key} (satu per baris)`" placeholder="Satu manfaat per baris (maks. 8)" />
          <div><AppButton variant="secondary" :loading="busy" @click="saveAddon(a)"><Save class="size-4" /> Simpan add-on</AppButton></div>
        </article>
        <p class="text-sm text-muted">Pembayaran add-on belum dibuka. Selama "Segera hadir", pelanggan hanya bisa mendaftar minat dari halaman Paket &amp; tagihan.</p>
      </div>
    </AppPanel>

    <AppPanel title="Jenis kontak & tautan bio link" flush>
      <template #actions>
        <div class="flex flex-wrap gap-1.5">
          <AppButton :variant="groupFilter === '' ? 'secondary' : 'ghost'" @click="groupFilter = ''">Semua</AppButton>
          <AppButton v-for="g in groups" :key="g" :variant="groupFilter === g ? 'secondary' : 'ghost'" @click="groupFilter = g">{{ g }}</AppButton>
        </div>
      </template>
      <ul>
        <li v-for="t in shownTypes" :key="t.public_id" class="grid items-center gap-3 border-t border-line px-4 py-2 text-sm sm:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)_96px_auto]">
          <span><span class="font-mono">{{ t.key }}</span> <AppBadge tone="outline" :dot="false" class="ml-1">{{ t.group }}</AppBadge></span>
          <AppInput v-model="t.label" maxlength="60" :aria-label="`Label ${t.key}`" @change="saveType(t, { label: t.label })" />
          <AppInput v-model="t.sort" type="number" :aria-label="`Urutan ${t.key}`" @change="saveType(t, { sort: Number(t.sort) })" />
          <AppToggle :model-value="t.is_active" :label="t.is_active ? 'Tampil' : 'Disembunyikan'" @update:model-value="(v) => saveType(t, { is_active: v })" />
        </li>
      </ul>
    </AppPanel>
  </template>
</template>
