<script setup>
// Tabel server-side (dipasangkan dengan usePagedList): header bisa diurutkan, ringkasan "1–25 dari N",
// pilihan jumlah per halaman, dan navigasi halaman. Isi sel lewat slot `#cell-{key}="{ row }"`.
// Ponsel (< sm): setiap baris menjadi kartu bertumpuk (label kolom di kiri, isi di kanan) — tanpa geser samping;
// urutan kolom bisa dipilih lewat menu "Urutkan". Kolom tanpa label (mis. tombol aksi) tampil sebagai baris tombol.
import { computed } from 'vue'
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-vue-next'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import AppTable from '@/components/ui/AppTable/index.vue'
import Pagination from '@/components/common/Pagination/index.vue'

const props = defineProps({
  columns: { type: Array, required: true }, // [{ key, label, sortable?, align?: 'right', class? }]
  rows: { type: Array, default: null },
  meta: { type: Object, default: null },
  sort: { type: String, default: '' },
  loading: Boolean,
  dense: Boolean,
  rowKey: { type: [String, Function], default: 'id' },
  emptyText: { type: String, default: 'Tidak ada data.' },
  perPageOptions: { type: Array, default: () => [10, 25, 50, 100] },
})
const emit = defineEmits(['sort', 'page', 'per-page'])

const keyOf = (row, i) => (typeof props.rowKey === 'function' ? props.rowKey(row) : row[props.rowKey] ?? i)
const range = computed(() => {
  const m = props.meta
  if (!m?.total) return null
  const from = (m.page - 1) * m.per_page + 1
  return `${from}–${Math.min(m.page * m.per_page, m.total)} dari ${m.total}`
})
const dirOf = (key) => (props.sort === key ? 'asc' : props.sort === `-${key}` ? 'desc' : null)
const ariaSort = (c) => (c.sortable ? ({ asc: 'ascending', desc: 'descending' })[dirOf(c.key)] ?? 'none' : undefined)

// Kartu ponsel: kolom pertama = judul kartu; kolom berlabel = pasangan label/isi; kolom tanpa label = aksi.
const head = computed(() => props.columns[0])
const detail = computed(() => props.columns.slice(1).filter((c) => c.label))
const actions = computed(() => props.columns.slice(1).filter((c) => !c.label))
const sortable = computed(() => props.columns.filter((c) => c.sortable))
// Menu "Urutkan" di ponsel: dua opsi per kolom (naik ↑ / turun ↓). Arah ikut dikirim sebagai argumen kedua event `sort`
// (usePagedList.toggleSort(key, dir)); klik header di desktop tetap hanya mengirim `key` dan membalik arah seperti biasa.
const sortOptions = computed(() => sortable.value.flatMap((c) => [
  { value: c.key, label: `${c.label} ↑` }, { value: `-${c.key}`, label: `${c.label} ↓` },
]))
const sortValue = computed(() => (sortOptions.value.some((o) => o.value === props.sort) ? props.sort : ''))
function pickSort(v) {
  const s = String(v)
  emit('sort', s.replace(/^-/, ''), s.startsWith('-') ? 'desc' : 'asc')
}
</script>

<template>
  <div class="grid gap-3">
    <AppSkeleton v-if="!rows" class="!h-60" />
    <div v-else :class="['relative', loading ? 'opacity-60' : '']" :aria-busy="loading || undefined">
      <!-- ponsel: kartu -->
      <div class="grid gap-3 sm:hidden">
        <div v-if="sortable.length" class="flex items-center gap-2 text-sm text-muted">
          <span class="flex-none">Urutkan</span>
          <AppSelect :model-value="sortValue" :options="sortOptions" placeholder="Bawaan" class="!min-h-10" aria-label="Urutkan daftar" @update:model-value="pickSort" />
        </div>
        <ul class="grid gap-3">
          <li v-for="(row, i) in rows" :key="keyOf(row, i)" class="grid gap-3 rounded-md border border-line bg-surface p-4">
            <div class="min-w-0 break-words">
              <slot :name="`cell-${head.key}`" :row="row">{{ row[head.key] ?? '—' }}</slot>
            </div>
            <dl v-if="detail.length" class="grid grid-cols-[minmax(0,auto)_minmax(0,1fr)] items-baseline gap-x-4 gap-y-2 border-t border-line pt-3 text-sm">
              <template v-for="c in detail" :key="c.key">
                <dt class="label !text-[10px]">{{ c.label }}</dt>
                <dd class="min-w-0 break-words" :class="c.align === 'right' ? 'text-right' : ''">
                  <slot :name="`cell-${c.key}`" :row="row">{{ row[c.key] ?? '—' }}</slot>
                </dd>
              </template>
            </dl>
            <div v-for="c in actions" :key="c.key" class="border-t border-line pt-3">
              <slot :name="`cell-${c.key}`" :row="row">{{ row[c.key] ?? '' }}</slot>
            </div>
          </li>
          <li v-if="!rows.length" class="rounded-md border border-line bg-surface p-6 text-center text-muted">{{ emptyText }}</li>
        </ul>
      </div>

      <!-- tablet & desktop: tabel -->
      <div class="hidden sm:block">
        <AppTable :dense="dense">
          <thead>
            <tr>
              <th v-for="c in columns" :key="c.key" :aria-sort="ariaSort(c)" :class="[c.align === 'right' ? '!text-right' : '', c.class]">
                <button class="cursor-pointer"
                  v-if="c.sortable"
                  type="button"
                  :class="['inline-flex items-center gap-1 uppercase hover:text-fg', dirOf(c.key) ? 'text-fg' : '']"
                  @click="emit('sort', c.key)"
                >
                  {{ c.label }}
                  <component :is="dirOf(c.key) === 'asc' ? ArrowUp : dirOf(c.key) === 'desc' ? ArrowDown : ArrowUpDown" class="size-3.5" aria-hidden="true" />
                </button>
                <template v-else>{{ c.label }}</template>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, i) in rows" :key="keyOf(row, i)">
              <td v-for="c in columns" :key="c.key" :class="[c.align === 'right' ? 'text-right' : '', c.class]">
                <slot :name="`cell-${c.key}`" :row="row">{{ row[c.key] ?? '—' }}</slot>
              </td>
            </tr>
            <tr v-if="!rows.length"><td :colspan="columns.length" class="text-center text-muted">{{ emptyText }}</td></tr>
          </tbody>
        </AppTable>
      </div>
    </div>

    <div v-if="meta && meta.total" class="grid gap-3 sm:flex sm:flex-wrap sm:items-center sm:justify-between">
      <div class="flex items-center justify-between gap-3 text-sm text-muted sm:justify-start">
        <span>{{ range }}</span>
        <AppSelect
          v-if="meta.total > perPageOptions[0]"
          :model-value="meta.per_page"
          :options="perPageOptions.map((n) => ({ value: n, label: `${n} / halaman` }))"
          class="!min-h-9 !w-auto"
          aria-label="Baris per halaman"
          @update:model-value="(v) => emit('per-page', v)"
        />
      </div>
      <Pagination :meta="meta" @page="(p) => emit('page', p)" />
    </div>
  </div>
</template>
