<script setup>
// Dashboard lead yang bisa diatur: periode 7/30/90 hari, pilih widget, urutan, lebar 1/2 kolom.
// Tata letak disimpan per pengguna di server; semua angka dihitung server sesuai cakupan izin lead.
import { computed, ref } from 'vue'
import { LayoutGrid, Plus, RotateCcw } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import LeadWidget from '@/components/leads/LeadWidget/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { leadsApi } from '@/api/workspace'
import { useAction } from '@/composables/useAsync'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'

const auth = useAuthStore()
const toast = useToastStore()
const { busy, run } = useAction()

const data = ref(null)
const layout = ref([]) // [{ id, size }]
const days = ref(30)
const editing = ref(false)
const loading = ref(false)
let saved = null // salinan sebelum mode atur, untuk Batal

async function load(d) {
  loading.value = true
  try {
    const res = (await leadsApi.dashboard(d)).data
    data.value = res
    days.value = res.days
    if (!editing.value) layout.value = res.layout.map((w) => ({ ...w }))
  } catch (e) {
    toast.error(e.message)
  } finally {
    loading.value = false
  }
}
load()

const catalog = computed(() => Object.fromEntries((data.value?.catalog ?? []).map((w) => [w.id, w])))
const available = computed(() => (data.value?.catalog ?? []).filter((w) => !layout.value.some((l) => l.id === w.id)))
const kpis = computed(() => layout.value.filter((w) => catalog.value[w.id]?.kind === 'kpi'))
const panels = computed(() => layout.value.filter((w) => catalog.value[w.id]?.kind !== 'kpi'))

function startEdit() {
  saved = { layout: layout.value.map((w) => ({ ...w })), days: days.value }
  editing.value = true
}
function cancelEdit() {
  layout.value = saved.layout
  editing.value = false
  if (days.value !== saved.days) load(saved.days)
}
/** Geser di dalam kelompoknya (KPI di baris atas, panel di bawah). */
function move(w, dir) {
  const group = catalog.value[w.id].kind === 'kpi' ? kpis.value : panels.value
  const j = group.indexOf(w) + dir
  if (j < 0 || j >= group.length) return
  const arr = layout.value
  const a = arr.indexOf(w)
  const b = arr.indexOf(group[j])
  ;[arr[a], arr[b]] = [arr[b], arr[a]]
}
const resize = (w) => (w.size = w.size === 2 ? 1 : 2)
const remove = (w) => (layout.value = layout.value.filter((x) => x !== w))
function add(meta) {
  layout.value.push({ id: meta.id, size: meta.size })
  if (!data.value.widgets[meta.id]) load(days.value) // ambil data widget baru
}
function reset() {
  layout.value = data.value.default_layout.map((w) => ({ ...w }))
  load(days.value)
}
async function save() {
  const res = await run(() => leadsApi.saveDashboard(layout.value, days.value), 'Tata letak dashboard disimpan').catch(() => null)
  if (res) {
    data.value = res.data
    layout.value = res.data.layout.map((w) => ({ ...w }))
    editing.value = false
  }
}
function setDays(d) {
  days.value = d
  load(d)
}
</script>

<template>
  <PageHeader :eyebrow="auth.contactLabel" title="Dashboard lead" back="/connections" :back-label="auth.contactLabel">
    <template v-if="!editing">
      <AppButton variant="secondary" :disabled="!data" @click="startEdit"><LayoutGrid class="size-5" /> Atur dashboard</AppButton>
    </template>
    <template v-else>
      <AppButton variant="ghost" @click="reset"><RotateCcw class="size-5" /> Bawaan</AppButton>
      <AppButton variant="secondary" @click="cancelEdit">Batal</AppButton>
      <AppButton :loading="busy" @click="save">Simpan tata letak</AppButton>
    </template>
  </PageHeader>

  <!-- filter periode: satu baris di atas semua grafik -->
  <div class="flex flex-wrap items-center gap-3">
    <AppSegmented :model-value="days" label="Periode" :options="[{ value: 7, label: '7 hari' }, { value: 30, label: '30 hari' }, { value: 90, label: '90 hari' }]" @update:model-value="setDays" />
    <span v-if="loading && data" class="text-sm text-muted" aria-live="polite">Memuat…</span>
  </div>

  <div v-if="editing" class="grid gap-3 rounded-md border border-dashed border-line-strong bg-surface-2 p-4">
    <p class="text-sm text-muted">Geser urutan dengan panah, ubah lebar dengan ikon kotak, lepas dengan ×. Tambah widget dari daftar di bawah.</p>
    <div v-if="available.length" class="flex flex-wrap gap-2">
      <AppButton v-for="w in available" :key="w.id" variant="secondary" @click="add(w)"><Plus class="size-4" /> {{ w.title }}</AppButton>
    </div>
    <p v-else class="text-sm text-muted">Semua widget sudah dipasang.</p>
  </div>

  <div v-if="!data" class="grid gap-4 md:grid-cols-4"><AppSkeleton v-for="i in 4" :key="i" class="!h-32" /><AppSkeleton class="!h-72 md:col-span-4" /></div>
  <template v-else>
    <div v-if="kpis.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <LeadWidget
        v-for="(w, i) in kpis" :key="w.id" :meta="catalog[w.id]" :data="data.widgets[w.id]" :size="1" :days="days" :editing="editing"
        :first="i === 0" :last="i === kpis.length - 1" :class="w.size === 2 ? 'sm:col-span-2' : ''"
        @move="(d) => move(w, d)" @resize="resize(w)" @remove="remove(w)"
      />
    </div>
    <div v-if="panels.length" class="grid items-start gap-4 md:grid-cols-2">
      <LeadWidget
        v-for="(w, i) in panels" :key="w.id" :meta="catalog[w.id]" :data="data.widgets[w.id]" :size="w.size" :days="days" :editing="editing"
        :first="i === 0" :last="i === panels.length - 1"
        @move="(d) => move(w, d)" @resize="resize(w)" @remove="remove(w)"
      />
    </div>
    <p v-if="!layout.length" class="rounded-md border border-line bg-surface p-8 text-center text-muted">Dashboard kosong. Pilih <strong>Atur dashboard</strong> untuk menambah widget.</p>
  </template>
</template>
