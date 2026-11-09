<script setup>
// Render isi hanya saat mendekati layar (IntersectionObserver) — galeri 40+ kartu SVG tetap ringan: yang belum terlihat
// cukup kotak kosong seukuran kartu, dan sekali tampil tidak dilepas lagi (gulir balik tidak berkedip).
import { onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps({
  aspect: { type: String, default: '85.6 / 54' }, // rasio placeholder (lebar / tinggi)
  margin: { type: String, default: '300px' }, // mulai render sedikit sebelum terlihat
})
const el = ref(null)
const shown = ref(typeof IntersectionObserver === 'undefined')
let io = null
onMounted(() => {
  if (shown.value) return
  io = new IntersectionObserver((entries) => {
    if (entries.some((e) => e.isIntersecting)) {
      shown.value = true
      io.disconnect()
    }
  }, { rootMargin: props.margin })
  io.observe(el.value)
})
onBeforeUnmount(() => io?.disconnect())
</script>

<template>
  <div ref="el">
    <slot v-if="shown" />
    <div v-else class="w-full rounded-sm border border-dashed border-line" :style="{ aspectRatio: aspect }" aria-hidden="true"></div>
  </div>
</template>
