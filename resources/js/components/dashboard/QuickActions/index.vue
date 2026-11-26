<script setup>
// Panel aksi cepat (menggantikan widget tukar): tautan nyata ke halaman terkait, tanpa tombol palsu.
defineProps({
  title: { type: String, default: 'Aksi cepat' },
  items: { type: Array, required: true }, // [{ label, sub, icon, to }]
})
</script>

<template>
  <section class="grid min-w-0 content-start gap-3 rounded-md border border-line bg-surface p-4 sm:p-5" :aria-label="title">
    <h2 class="font-sans text-lg font-semibold">{{ title }}</h2>
    <ul class="grid gap-2">
      <li v-for="it in items" :key="it.to">
        <RouterLink :to="it.to" class="flex min-h-14 items-center gap-3 rounded-md border border-line px-3 py-2.5 no-underline hover:bg-surface-2">
          <span class="grid size-10 flex-none place-items-center rounded-md bg-surface-2 text-accent-text" aria-hidden="true">
            <component :is="it.icon" class="size-5" />
          </span>
          <span class="grid min-w-0 flex-1">
            <span class="truncate font-medium text-fg">{{ it.label }}</span>
            <span class="truncate text-xs text-muted sm:text-sm">{{ it.sub }}</span>
          </span>
        </RouterLink>
      </li>
    </ul>
  </section>
</template>
