<script setup>
// Halaman status terpusat (P-02, P-03, QR kedaluwarsa, 404): bilah merek, panel berisi ilustrasi kartu + ikon status,
// label status, judul, penjelasan, aksi; opsional ajakan "buat kartu digital" di bawah (halaman publik tap.).
import { computed } from 'vue'
import { ArrowRight } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import BrandLogo from '@/components/common/BrandLogo/index.vue'
import SignalRings from '@/components/common/SignalRings/index.vue'
import { config } from '@/config'

const props = defineProps({
  title: String,
  text: String,
  logo: String, // logo workspace perusahaan (menggantikan merek TapCard di bilah atas)
  logoAlt: String,
  icon: { type: [Object, Function], default: null }, // ikon lucide di tengah ilustrasi
  tone: { type: String, default: 'muted' }, // ok | warn | danger | info | muted
  badge: { type: String, default: null }, // label status singkat
  promo: Boolean, // ajakan TapCard di kaki halaman
})
const TONE = { ok: 'text-ok', warn: 'text-warn', danger: 'text-danger', info: 'text-info', muted: 'text-muted' }
const iconClass = computed(() => TONE[props.tone] ?? TONE.muted)
const home = config.marketingUrl || '/'
</script>

<template>
  <div class="grid min-h-dvh grid-rows-[auto_1fr_auto] bg-bg">
    <header class="flex h-16 items-center justify-center px-4">
      <img v-if="logo" :src="logo" :alt="logoAlt" class="h-8 w-auto" />
      <a v-else :href="home" class="no-underline" aria-label="TapCard"><BrandLogo /></a>
    </header>

    <main class="grid place-items-center px-4 py-6">
      <section class="grid w-full max-w-[440px] justify-items-center gap-5 rounded-lg border border-line bg-surface px-6 pt-10 pb-8 text-center sm:px-8">
        <!-- ilustrasi: kartu dengan cincin sinyal samar + ikon status -->
        <div class="relative grid h-[104px] w-40 place-items-center" aria-hidden="true">
          <SignalRings class="absolute -top-6 -right-8 size-24 text-line" />
          <div class="relative grid h-full w-full -rotate-6 place-items-center rounded-xl border border-line-strong bg-surface-2">
            <span class="absolute top-3 left-3 h-1.5 w-8 rounded-full bg-line-strong"></span>
            <span class="absolute bottom-3 left-3 h-1 w-14 rounded-full bg-line"></span>
            <slot name="icon">
              <component :is="icon" v-if="icon" :class="['size-9', iconClass]" />
              <SignalRings v-else class="size-12 text-line-strong" />
            </slot>
          </div>
        </div>

        <div class="grid justify-items-center gap-2">
          <AppBadge v-if="badge" :tone="tone">{{ badge }}</AppBadge>
          <h1 class="font-display text-[26px] leading-tight font-bold tracking-[-.01em]">{{ title }}</h1>
          <p v-if="text" class="max-w-[360px] text-muted">{{ text }}</p>
        </div>

        <div v-if="$slots.default" class="grid w-full justify-items-center gap-3"><slot /></div>
      </section>
    </main>

    <footer class="px-4 pb-6 text-center text-sm text-muted">
      <a v-if="promo" :href="home" class="inline-flex items-center gap-1.5 font-medium text-fg no-underline hover:text-accent-text">
        Punya kartu nama digital sendiri — gratis dengan TapCard <ArrowRight class="size-4" />
      </a>
      <span v-else>TapCard</span>
    </footer>
  </div>
</template>
