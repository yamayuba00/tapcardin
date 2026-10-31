<script setup>
// P-01 / DBD §9 — bio link per kartu: foto + nama + jabatan → Simpan Kontak → tombol cepat → bio → tautan → Tukar Kontak.
// Tema (preset, aksen, tata letak header, bentuk foto, sudut, gaya tombol & tautan, font) dari App\Support\Bio\BioTheme;
// warna masuk sebagai variabel CSS semantik di subtree ini, jadi tetap memakai token DBD (tanpa hex).
import { computed, ref } from 'vue'
import { Download, Mail, MessageCircle, Phone, Users } from 'lucide-vue-next'
import ContactLink from '@/components/profile/ContactLink/index.vue'
import ExchangeSheet from '@/components/profile/ExchangeSheet/index.vue'
import { config } from '@/config'
import { AVATAR_CLASS, BIO_DEFAULTS, CORNER_CLASS, FONT_CLASS, themeStyle } from '@/lib/bioTheme'
import { initials } from '@/lib/format'

const props = defineProps({ profile: { type: Object, required: true }, preview: Boolean })
const exchanging = ref(false)

const t = computed(() => ({ ...BIO_DEFAULTS, ...props.profile.theme }))
const style = computed(() => themeStyle(props.profile.theme))
const corner = computed(() => CORNER_CLASS[t.value.corners])
const role = computed(() => [props.profile.title, props.profile.company].filter(Boolean).join(' · '))
const qris = ref(null) // gambar QRIS yang dibuka layar penuh
const quick = computed(() => [
  props.profile.quick.whatsapp && { key: 'wa', href: props.profile.quick.whatsapp, icon: MessageCircle, label: 'WhatsApp' },
  props.profile.quick.phone && { key: 'tel', href: props.profile.quick.phone, icon: Phone, label: 'Telepon' },
  props.profile.quick.email && { key: 'mail', href: props.profile.quick.email, icon: Mail, label: 'Email' },
].filter(Boolean))
const rings = [[30, 0.5], [60, 0.32], [90, 0.2], [120, 0.1]]

const primaryBtn = computed(() => [
  'flex min-h-14 w-full items-center justify-center gap-2 px-6 text-base font-semibold no-underline transition-opacity',
  corner.value,
  t.value.button === 'solid' ? 'bg-accent text-accent-ink hover:opacity-90' : 'border-2 border-accent text-accent-text hover:bg-surface-2',
  props.preview ? 'pointer-events-none' : '',
])
const secondaryBtn = computed(() => ['flex min-h-14 w-full items-center justify-center gap-2 border border-line-strong bg-surface px-6 font-semibold text-fg hover:bg-surface-2', corner.value])
const avatarCls = computed(() => [AVATAR_CLASS[t.value.avatar], t.value.header === 'left' ? 'size-20' : 'size-24'])
const nameCls = computed(() => [
  FONT_CLASS[t.value.font], 'relative leading-none font-bold tracking-[-.015em] [overflow-wrap:anywhere]',
  t.value.header === 'left' ? 'text-[32px]' : 'text-[40px]',
])
</script>

<template>
  <div class="min-h-full bg-bg text-fg" :data-theme="t.mode" :style="style">
    <main class="mx-auto flex min-h-full w-full max-w-[480px] flex-col">
      <!-- Header: cover = pita aksen + foto menumpang; left = foto di kiri; center = klasik -->
      <header v-if="t.header === 'cover'" class="relative grid justify-items-center text-center">
        <div class="h-32 w-full bg-accent" aria-hidden="true"></div>
        <img v-if="profile.brand?.logo_url" :src="profile.brand.logo_url" :alt="profile.brand.name" class="absolute top-4 left-4 h-8 w-auto" />
        <img v-if="profile.avatar_url" :src="profile.avatar_url" alt="" :class="['-mt-12 border-4 border-bg object-cover', avatarCls]" />
        <div v-else :class="['-mt-12 grid place-items-center border-4 border-bg bg-surface-2 font-display text-[34px] font-bold text-accent-text', avatarCls]" aria-hidden="true">
          {{ initials(profile.display_name) }}
        </div>
        <div class="grid gap-2 px-6 pt-3 pb-6">
          <h1 :class="nameCls">{{ profile.display_name }}</h1>
          <p v-if="role" class="label !text-accent-text">{{ role }}</p>
        </div>
      </header>

      <header
        v-else
        :class="['relative overflow-hidden px-6 pt-12 pb-6', t.header === 'left' ? 'flex items-center gap-4 text-left' : 'grid min-h-[220px] justify-items-center gap-2 text-center']"
      >
        <svg v-if="t.decoration === 'rings'" class="pointer-events-none absolute -top-16 -right-16 size-60 text-accent" viewBox="0 0 240 240" fill="none" stroke="currentColor" aria-hidden="true">
          <circle v-for="[r, o] in rings" :key="r" cx="120" cy="120" :r="r" :stroke-opacity="o" />
        </svg>
        <img v-if="profile.brand?.logo_url" :src="profile.brand.logo_url" :alt="profile.brand.name" class="absolute top-4 left-4 h-8 w-auto" />
        <img v-if="profile.avatar_url" :src="profile.avatar_url" alt="" :class="['relative flex-none border-2 border-accent object-cover', t.header === 'left' ? '' : 'mb-2', avatarCls]" />
        <div v-else :class="['relative grid flex-none place-items-center border-2 border-accent bg-surface-2 font-display text-[34px] font-bold text-accent-text', t.header === 'left' ? '' : 'mb-2', avatarCls]" aria-hidden="true">
          {{ initials(profile.display_name) }}
        </div>
        <div :class="['relative grid min-w-0 gap-2', t.header === 'left' ? '' : 'justify-items-center']">
          <h1 :class="nameCls">{{ profile.display_name }}</h1>
          <p v-if="role" class="label !text-accent-text">{{ role }}</p>
        </div>
      </header>

      <div class="grid flex-1 content-start gap-6 px-4 pb-6">
        <div class="grid gap-3">
          <a :href="profile.vcard_url || undefined" :class="primaryBtn" :aria-disabled="preview || undefined"><Download class="size-5" /> Simpan Kontak</a>
          <div v-if="quick.length" :class="['flex gap-3', t.header === 'left' ? 'justify-start' : 'justify-center']">
            <a
              v-for="q in quick"
              :key="q.key"
              :href="q.href"
              :aria-label="`${q.label} ${profile.display_name}`"
              :class="['grid size-14 place-items-center border border-line-strong bg-surface text-fg hover:bg-surface-2', corner]"
            >
              <component :is="q.icon" class="size-[22px]" aria-hidden="true" />
            </a>
          </div>
        </div>

        <p v-if="profile.bio" :class="['whitespace-pre-line', t.header === 'center' || t.header === 'cover' ? 'text-center' : '']">{{ profile.bio }}</p>

        <template v-if="profile.links.length">
          <ul :class="t.links === 'list' ? 'border-t border-line' : t.links === 'buttons' ? 'grid gap-3' : 'grid grid-cols-2 gap-3'">
            <li v-for="(l, i) in profile.links" :key="l.id || i" :class="t.links === 'grid' && profile.links.length % 2 && i === profile.links.length - 1 ? 'col-span-2' : ''">
              <ContactLink :link="l" :variant="t.links" :corner="corner" :preview="preview" @image="(x) => (qris = x)" />
            </li>
          </ul>
        </template>

        <button class="cursor-pointer" v-if="profile.exchange" type="button" :class="secondaryBtn" @click="exchanging = true"><Users class="size-5" /> Tukar Kontak</button>
      </div>

      <footer class="grid justify-items-center gap-3 px-4 pt-6 pb-8 text-center">
        <span v-if="profile.event" class="label">Bertemu di {{ profile.event }}</span>
        <p class="label">Dibuat dengan <a :href="config.marketingUrl" class="!text-muted">TapCard</a></p>
      </footer>
    </main>

    <!-- QRIS: putih di atas gelap & sebesar mungkin agar mudah di-scan aplikasi pembayaran -->
    <div v-if="qris" class="fixed inset-0 z-50 grid place-items-center bg-scrim p-6" role="dialog" aria-modal="true" :aria-label="qris.label" @click="qris = null">
      <figure class="grid w-full max-w-sm gap-3 rounded-lg bg-bone-0 p-5 text-center text-ink-950">
        <img :src="qris.image" :alt="qris.label" class="w-full" />
        <figcaption class="text-sm">{{ qris.label }} · ketuk di luar untuk menutup</figcaption>
      </figure>
    </div>
    <ExchangeSheet v-if="exchanging" :profile="profile" :preview="preview" @close="exchanging = false" />
  </div>
</template>
