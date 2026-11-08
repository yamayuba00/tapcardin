<script setup>
// Deret bar status (per hari di halaman status, per cek di panel). Warna = status (ok / warn / danger / tanpa data),
// tetapi makna tidak hanya dari warna: setiap bar punya label (tooltip saat hover / fokus, dibaca pembaca layar).
import { ref } from 'vue'

const props = defineProps({
  /** @type {{key: string, tone: 'ok'|'warn'|'danger'|'none', label: string}[]} */
  items: { type: Array, required: true },
  label: { type: String, required: true }, // nama deret untuk pembaca layar
  height: { type: String, default: 'h-8' },
})
const TONE = { ok: 'bg-ok', warn: 'bg-warn', danger: 'bg-danger', none: 'bg-line' }
const active = ref(-1)
</script>

<template>
  <div class="relative" @mouseleave="active = -1">
    <!-- 90 bar harus muat di ponsel 320px: celah 1px di layar kecil, 2px mulai sm -->
    <ul class="flex items-stretch gap-px sm:gap-[2px]" :class="height" :aria-label="label">
      <li
        v-for="(it, i) in props.items"
        :key="it.key"
        tabindex="0"
        class="min-w-px flex-1 rounded-[1px] outline-offset-2 focus-visible:outline-2 focus-visible:outline-accent sm:rounded-[2px]"
        :class="[TONE[it.tone], active === i ? 'opacity-70' : '']"
        :aria-label="it.label"
        @mouseenter="active = i"
        @focus="active = i"
        @blur="active = -1"
      ></li>
    </ul>
    <p
      v-if="active >= 0"
      class="pointer-events-none absolute top-full z-10 mt-2 max-w-64 -translate-x-1/2 rounded-md border border-line bg-surface px-3 py-2 text-xs whitespace-pre-line text-fg"
      :style="{ left: `clamp(80px, ${((active + 0.5) / props.items.length) * 100}%, calc(100% - 80px))` }"
      role="status"
    >{{ props.items[active].label }}</p>
  </div>
</template>
