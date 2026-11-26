<script setup>
// Tabel "Aktivitas terbaru" (referensi): desktop/tablet = tabel dalam kartu (scroll hanya di dalam kartu);
// ponsel = baris kartu. Kolom Tren berisi "—" karena API tidak menyediakan deret tap per item.
import { Nfc, Users } from 'lucide-vue-next'
import { relative } from '@/lib/format'

const props = defineProps({
  rows: { type: Array, required: true }, // hasil groupRecent(): { type, at, title, id?, meta?, count? }
  now: { type: Date, required: true },
  moreCount: { type: Number, default: 0 }, // baris yang disembunyikan; 0 = tidak ada tombol
  expanded: { type: Boolean, default: false },
})
const emit = defineEmits(['toggle'])

const detail = (r) => [...Object.values(r.meta ?? {}), ...(r.count > 1 ? [`${r.count} kali`] : [])].join(' · ')
const iconOf = (r) => (r.type === 'tap' ? Nfc : Users)
</script>

<template>
  <section class="min-w-0 rounded-md border border-line bg-surface p-4 sm:p-5" aria-labelledby="activity-title">
    <header class="mb-3 flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
      <h2 id="activity-title" class="font-sans text-lg font-semibold">Aktivitas terbaru</h2>
      <div class="flex flex-wrap items-center gap-2"><slot name="actions" /></div>
    </header>

    <div class="hidden overflow-x-auto md:block">
      <table class="w-full min-w-[560px] text-sm">
        <thead>
          <tr class="border-b border-line text-left text-xs text-muted">
            <th scope="col" class="py-2 pr-3 font-medium">Aktivitas</th>
            <th scope="col" class="py-2 pr-3 font-medium">Detail</th>
            <th scope="col" class="py-2 pr-3 font-medium">Waktu</th>
            <th scope="col" class="py-2 text-right font-medium">Tren</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(r, i) in rows" :key="i" class="border-b border-line last:border-b-0">
            <td class="py-3 pr-3">
              <span class="flex min-w-0 items-center gap-3">
                <span class="grid size-9 flex-none place-items-center rounded-md bg-surface-2 text-muted" aria-hidden="true"><component :is="iconOf(r)" class="size-4" /></span>
                <RouterLink v-if="r.id" :to="`/connections/${r.id}`" class="min-w-0 truncate font-medium !text-fg">{{ r.title }}</RouterLink>
                <span v-else class="min-w-0 truncate font-medium">{{ r.title }}</span>
              </span>
            </td>
            <td class="py-3 pr-3 text-muted"><span class="block max-w-[260px] truncate">{{ detail(r) || '—' }}</span></td>
            <td class="py-3 pr-3 whitespace-nowrap text-muted">{{ relative(r.at, now) }}</td>
            <td class="py-3 text-right text-muted"><span aria-label="Tren tidak tersedia">—</span></td>
          </tr>
          <tr v-if="!rows.length"><td colspan="4" class="py-3 text-muted">Belum ada aktivitas.</td></tr>
        </tbody>
      </table>
    </div>

    <ul class="grid gap-2 md:hidden">
      <li v-for="(r, i) in rows" :key="i" class="flex min-h-14 items-center gap-3 rounded-md border border-line p-3">
        <span class="grid size-9 flex-none place-items-center rounded-md bg-surface-2 text-muted" aria-hidden="true"><component :is="iconOf(r)" class="size-4" /></span>
        <span class="grid min-w-0 flex-1">
          <RouterLink v-if="r.id" :to="`/connections/${r.id}`" class="truncate font-medium !text-fg">{{ r.title }}</RouterLink>
          <span v-else class="truncate font-medium">{{ r.title }}</span>
          <span class="truncate text-xs text-muted">{{ relative(r.at, now) }}<template v-if="detail(r)"> · {{ detail(r) }}</template></span>
        </span>
      </li>
      <li v-if="!rows.length" class="py-3 text-muted">Belum ada aktivitas.</li>
    </ul>

    <button v-if="moreCount" type="button" class="mt-3 flex min-h-11 w-full cursor-pointer items-center justify-center rounded-md border border-line text-sm font-medium text-muted hover:bg-surface-2 hover:text-fg" @click="emit('toggle')">
      {{ expanded ? 'Tampilkan lebih sedikit' : `Tampilkan ${moreCount} lainnya` }}
    </button>
  </section>
</template>
