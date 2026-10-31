<script setup>
// Status simpan di bilah editor: titik warna + teks singkat (tersimpan / belum disimpan / menyimpan…).
defineProps({ dirty: Boolean, saving: Boolean })
</script>

<template>
  <span class="hidden items-center gap-2 text-sm text-muted sm:inline-flex" role="status" aria-live="polite">
    <span class="size-2 rounded-full" :class="saving ? 'animate-pulse bg-info' : dirty ? 'bg-warn' : 'bg-ok'" aria-hidden="true"></span>
    {{ saving ? 'Menyimpan…' : dirty ? 'Belum disimpan' : 'Tersimpan' }}
  </span>
</template>
