<script setup>
// DBD §11 Tabel — header --text-2 mono kapital, pemisah --line, hover --surface-2; dense = 44 px (panel pemilik).
defineProps({ dense: Boolean })
</script>

<template>
  <!-- Ponsel: kolom tidak dipaksa menyempit; tabel boleh digeser ke samping di dalam kartunya (bukan halaman). -->
  <div class="overflow-x-auto overscroll-x-contain rounded-md border border-line bg-surface" tabindex="0" role="region" aria-label="Tabel (geser ke samping untuk melihat semua kolom)">
    <table
      :class="[
        'w-full min-w-max border-collapse text-sm sm:min-w-0',
        '[&_th]:border-b [&_th]:border-line [&_th]:px-4 [&_th]:py-3 [&_th]:text-left [&_th]:font-mono [&_th]:text-xs [&_th]:font-medium [&_th]:tracking-[.14em] [&_th]:whitespace-nowrap [&_th]:text-muted [&_th]:uppercase',
        '[&_td]:border-b [&_td]:border-line [&_td]:px-4 [&_td]:align-middle [&_tr:last-child_td]:border-b-0 [&_tbody_tr:hover]:bg-surface-2',
        dense ? '[&_td]:h-11 [&_td]:py-2' : '[&_td]:h-[52px] [&_td]:py-3',
        '[&_a]:text-fg',
      ]"
    >
      <slot />
    </table>
  </div>
</template>
