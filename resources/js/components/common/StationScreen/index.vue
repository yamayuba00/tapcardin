<script setup>
// DBD §10.4 — layar produksi: teks besar kontras tinggi; --solid-ok sukses, --solid-danger gagal, teks --on-solid.
defineProps({ state: { type: String, default: 'idle' } }) // idle | ok | fail
</script>

<template>
  <div
    role="status"
    aria-live="assertive"
    :class="[
      'grid min-h-[420px] place-items-center rounded-lg border p-6 text-center sm:p-8',
      state === 'ok' ? 'border-solid-ok bg-solid-ok text-on-solid' : state === 'fail' ? 'border-solid-danger bg-solid-danger text-on-solid' : 'border-line bg-surface',
    ]"
  >
    <slot />
  </div>
</template>
