<script setup>
// DBD §10.4 — layar produksi: teks besar kontras tinggi; --solid-ok sukses, --solid-danger gagal, teks --on-solid.
defineProps({ state: { type: String, default: 'idle' } }) // idle | ok | fail
</script>

<template>
  <div
    role="status"
    aria-live="assertive"
    :class="[
      'grid min-h-[calc(100vh-180px)] place-items-center rounded-lg border p-8 text-center',
      state === 'ok' ? 'border-solid-ok bg-solid-ok text-on-solid' : state === 'fail' ? 'border-solid-danger bg-solid-danger text-on-solid' : 'border-line bg-surface',
    ]"
  >
    <slot />
  </div>
</template>
