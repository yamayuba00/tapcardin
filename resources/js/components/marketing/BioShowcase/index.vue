<script setup>
// Demo bio link: pengunjung mencoba preset tema & tata letak pada contoh profil — komponen yang sama
// dengan halaman yang terbuka saat kartu di-tap.
import { computed, reactive } from 'vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import PublicProfileView from '@/components/profile/PublicProfileView/index.vue'
import { BIO_DEFAULTS, resolveTheme } from '@/lib/bioTheme'

const props = defineProps({ catalog: { type: Object, required: true }, sample: { type: Object, required: true } })
const choice = reactive({ ...BIO_DEFAULTS })
const profile = computed(() => ({ ...props.sample, theme: resolveTheme(props.catalog, choice) }))
// warna latar & aksen bawaan tiap preset (kelas token, bukan style inline — CSP melarang style atribut di HTML SSR)
const SWATCH = {
  onyx: ['bg-onyx-950', 'bg-champagne-500'],
  ivory: ['bg-bone-50', 'bg-champagne-500'],
  garis: ['bg-bone-0', 'bg-ink-950'],
  kreatif: ['bg-onyx-950', 'bg-coral-300'],
  natural: ['bg-bone-100', 'bg-umber-600'],
  metal: ['bg-onyx-800', 'bg-bone-200'],
  sage: ['bg-bone-50', 'bg-sage-700'],
  korporat: ['bg-bone-0', 'bg-sky-700'],
}
const CONTROLS = [
  { key: 'header', label: 'Header', options: [{ value: 'center', label: 'Tengah' }, { value: 'left', label: 'Kiri' }, { value: 'cover', label: 'Sampul' }] },
  { key: 'links', label: 'Tautan', options: [{ value: 'list', label: 'Daftar' }, { value: 'buttons', label: 'Tombol' }, { value: 'grid', label: 'Grid' }] },
  { key: 'corners', label: 'Sudut', options: [{ value: 'rounded', label: 'Membulat' }, { value: 'sharp', label: 'Tajam' }, { value: 'pill', label: 'Kapsul' }] },
  { key: 'button', label: 'Tombol', options: [{ value: 'solid', label: 'Penuh' }, { value: 'outline', label: 'Garis' }] },
]
</script>

<template>
  <div class="grid grid-cols-[minmax(0,1fr)] items-center gap-10 lg:grid-cols-[minmax(0,1fr)_400px]">
    <div class="grid gap-6">
      <div class="grid gap-2">
        <span class="label">Coba tema</span>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="p in catalog.presets"
            :key="p.key"
            type="button"
            :aria-pressed="choice.preset === p.key"
            class="cursor-pointer inline-flex h-10 items-center gap-2 rounded-full border border-line pr-4 pl-1.5 text-sm aria-pressed:border-accent aria-pressed:text-accent-text"
            @click="choice.preset = p.key"
          >
            <span :data-theme="p.mode" :class="['grid size-7 place-items-center rounded-full border border-line', SWATCH[p.key]?.[0] ?? 'bg-bg']">
              <span :class="['size-3 rounded-full', SWATCH[p.key]?.[1] ?? 'bg-accent']"></span>
            </span>
            {{ p.name }}
          </button>
        </div>
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <div v-for="c in CONTROLS" :key="c.key" class="grid gap-2">
          <span class="label">{{ c.label }}</span>
          <AppSegmented v-model="choice[c.key]" :label="c.label" :options="c.options" />
        </div>
      </div>
      <slot />
    </div>

    <div class="mx-auto w-full max-w-[380px] rounded-[36px] border border-line-strong bg-surface p-3">
      <div class="h-[640px] overflow-y-auto overscroll-auto rounded-[26px] scrollbar-none" tabindex="0" aria-label="Contoh bio link (bisa di-scroll)">
        <PublicProfileView :profile="profile" preview />
      </div>
    </div>
  </div>
</template>
