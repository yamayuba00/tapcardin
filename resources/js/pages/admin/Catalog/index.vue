<script setup>
// Katalog — harga lanyard (lebar tali: cetak 1/2 sisi, minimal pesan; pengait) dan jenis kontak bio link
// (aktif/nonaktif, label, urutan). Perubahan harga langsung dipakai editor & katalog lanyard pelanggan.
import { computed, reactive, ref, watch } from 'vue'
import { Plus, Save } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
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
watch(c, (v) => {
  if (!v) return
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
async function saveType(t, patch) {
  const res = await run(() => adminApi.updateContactType(t.public_id, patch)).catch(() => null)
  if (res) c.value = res.data
}
</script>

<template>
  <PageHeader eyebrow="Bisnis" title="Katalog" />
  <AppSkeleton v-if="!c" class="!h-60" />
  <template v-else>
    <AppPanel title="Lanyard · lebar tali" flush>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[760px] text-sm">
          <thead>
            <tr class="text-left text-muted">
              <th class="px-4 py-2 font-medium">Lebar</th><th class="px-4 py-2 font-medium">Label</th>
              <th class="px-4 py-2 font-medium">Cetak 1 sisi (Rp)</th><th class="px-4 py-2 font-medium">Cetak 2 sisi (Rp)</th>
              <th class="px-4 py-2 font-medium">Min. pesan</th><th class="px-4 py-2 font-medium">Aktif</th><th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="w in widths" :key="w.public_id" class="border-t border-line">
              <td class="px-4 py-2 font-mono">{{ w.width_mm }} mm</td>
              <td class="px-4 py-2"><AppInput v-model="w.label" maxlength="60" :aria-label="`Label ${w.width_mm} mm`" /></td>
              <td class="px-4 py-2"><AppInput v-model="w.price_single" type="number" min="0" :aria-label="`Harga 1 sisi ${w.width_mm} mm`" /></td>
              <td class="px-4 py-2"><AppInput v-model="w.price_double" type="number" min="0" :aria-label="`Harga 2 sisi ${w.width_mm} mm`" /></td>
              <td class="px-4 py-2"><AppInput v-model="w.min_qty" type="number" min="1" class="max-w-24" :aria-label="`Minimal pesan ${w.width_mm} mm`" /></td>
              <td class="px-4 py-2"><AppToggle v-model="w.is_active" :label="w.is_active ? 'Ya' : 'Tidak'" /></td>
              <td class="px-4 py-2 text-right"><AppButton variant="secondary" :loading="busy" @click="saveWidth(w)"><Save class="size-4" /> Simpan</AppButton></td>
            </tr>
          </tbody>
        </table>
      </div>
    </AppPanel>

    <AppPanel title="Lanyard · pengait & aksesori" flush>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] text-sm">
          <thead>
            <tr class="text-left text-muted">
              <th class="px-4 py-2 font-medium">Nama</th><th class="px-4 py-2 font-medium">Deskripsi</th>
              <th class="px-4 py-2 font-medium">Harga (Rp)</th><th class="px-4 py-2 font-medium">Aktif</th><th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="h in hardware" :key="h.public_id" class="border-t border-line">
              <td class="px-4 py-2"><AppInput v-model="h.name" maxlength="60" :aria-label="`Nama ${h.key}`" /></td>
              <td class="px-4 py-2"><AppInput v-model="h.description" maxlength="200" :aria-label="`Deskripsi ${h.name}`" /></td>
              <td class="px-4 py-2"><AppInput v-model="h.price" type="number" min="0" class="max-w-32" :aria-label="`Harga ${h.name}`" /></td>
              <td class="px-4 py-2"><AppToggle v-model="h.is_active" :label="h.is_active ? 'Ya' : 'Tidak'" /></td>
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
