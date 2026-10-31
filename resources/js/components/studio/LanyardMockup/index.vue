<script setup>
// Pratinjau gabungan kartu + lanyard: dua tali (pola diulang, sisi kanan terbalik seperti aslinya) menyatu di pengait,
// kartu pasangan menggantung di bawahnya. Skala proporsional (mm → px), hanya bagian bawah tali yang terlihat.
import { computed } from 'vue'
import { CreditCard } from 'lucide-vue-next'
import CardSide from '@/components/studio/CardSide/index.vue'
import LanyardStrip from '@/components/studio/LanyardStrip/index.vue'

const props = defineProps({
  lanyard: { type: Object, required: true }, // sisi pola (front)
  back: { type: Object, default: null }, // sisi belakang pola (tali kanan menampilkan sisi ini bila 2 sisi)
  card: { type: Object, default: null }, // sisi depan kartu pasangan
  kit: { type: Object, default: null },
  vars: { type: Object, default: () => ({}) },
  qr: { type: Object, default: null },
  hardware: { type: String, default: 'lobster' },
  scale: { type: Number, default: 2.1 }, // px per mm
})
const S = computed(() => props.scale)
const strap = 165 // mm tali yang terlihat per sisi
const angle = 13 // derajat dari vertikal
const W = 380
const hx = W / 2
const hy = computed(() => Math.round(Math.cos((angle * Math.PI) / 180) * strap * S.value) + 8)
const strapStyle = (left) => ({
  position: 'absolute', left: `${hx - strap * S.value}px`, top: `${hy.value - (props.lanyard.size.h * S.value) / 2}px`,
  transformOrigin: 'right center', transform: `rotate(${left ? 90 - angle : 90 + angle}deg)`,
})
const card = computed(() => props.card)
const cardW = computed(() => (card.value?.size.w ?? 54) * S.value)
const cardH = computed(() => (card.value?.size.h ?? 85.6) * S.value)
const hwH = computed(() => ({ lobster: 34, j_hook: 30, badge_reel: 52, breakaway: 34 })[props.hardware] ?? 34)
const height = computed(() => hy.value + hwH.value + cardH.value + 24)
</script>

<template>
  <div class="relative mx-auto overflow-hidden rounded-md bg-surface-2" :style="{ width: `${W}px`, height: `${height}px` }" role="img" :aria-label="`Pratinjau lanyard dengan ${card ? 'kartu pasangan' : 'kartu (belum dipasangkan)'}`">
    <div :style="strapStyle(true)"><LanyardStrip :side="lanyard" :kit="kit" :vars="vars" :length="strap" :scale="S" /></div>
    <div :style="strapStyle(false)"><LanyardStrip :side="back || lanyard" :kit="kit" :vars="vars" :length="strap" :scale="S" /></div>

    <!-- pengait -->
    <svg :style="{ position: 'absolute', left: `${hx - 22}px`, top: `${hy - 6}px` }" width="44" :height="hwH + 6" viewBox="0 0 44 58" class="text-muted" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
      <rect x="12" y="2" width="20" height="9" rx="2" class="fill-surface" />
      <template v-if="hardware === 'badge_reel'">
        <line x1="22" y1="11" x2="22" y2="16" />
        <circle cx="22" cy="30" r="13" class="fill-surface" /><circle cx="22" cy="30" r="4" />
        <line x1="22" y1="43" x2="22" y2="56" stroke-dasharray="2 2" />
      </template>
      <template v-else-if="hardware === 'j_hook'">
        <line x1="22" y1="11" x2="22" y2="20" /><circle cx="22" cy="23" r="3" />
        <path d="M22 26 v14 a6 6 0 1 1 -10 -4" />
      </template>
      <template v-else>
        <line x1="22" y1="11" x2="22" y2="18" /><circle cx="22" cy="21" r="3" />
        <path d="M22 24 c-7 2 -9 12 -4 18 c3 3 8 2 10 -2 c1.5 -4 -1 -8 -4 -9" /><line x1="25" y1="31" x2="20" y2="36" />
      </template>
    </svg>
    <span v-if="hardware === 'breakaway'" class="absolute top-2 left-1/2 -translate-x-1/2 rounded-full bg-surface px-2 py-0.5 text-[10px] text-muted">pengaman putus di belakang leher</span>

    <!-- kartu -->
    <div class="absolute" :style="{ left: `${hx - cardW / 2}px`, top: `${hy + hwH}px`, width: `${cardW}px` }">
      <div v-if="card" class="overflow-hidden rounded-[6px]"><CardSide :side="card" :kit="kit" :vars="vars" :qr="qr" trim /></div>
      <div v-else class="grid place-items-center gap-1 rounded-[6px] border-2 border-dashed border-line-strong p-3 text-center text-xs text-muted" :style="{ height: `${cardH}px` }">
        <CreditCard class="size-6" /> Pasangkan kartu untuk melihat pratinjau gabungan
      </div>
    </div>
  </div>
</template>
