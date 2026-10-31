<script setup>
// Tabel server-side (dipasangkan dengan usePagedList): header bisa diurutkan, ringkasan "1–25 dari N",
// pilihan jumlah per halaman, dan navigasi halaman. Isi sel lewat slot `#cell-{key}="{ row }"`.
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
</script>

<template>
  <div class="grid gap-3">
    <AppSkeleton v-if="!rows" class="!h-60" />
    <div v-else :class="['relative', loading ? 'opacity-60' : '']" :aria-busy="loading || undefined">
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

    <div v-if="meta && meta.total" class="flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-3 text-sm text-muted">
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
