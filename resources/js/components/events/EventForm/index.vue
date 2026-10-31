<script setup>
// Form event (buat & ubah) di dalam AppModal: Info · Waktu (tanggal + jam, chip durasi cepat) · Opsi.
// Tombol aksi ada di footer modal; tombol submit memakai atribut form="{id}".
import { computed, reactive, watch } from 'vue'
import { CalendarDays, Clock, MapPin, Radio, UserRoundCheck } from 'lucide-vue-next'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'

const props = defineProps({
  id: { type: String, required: true },
  initial: { type: Object, default: null }, // event yang diubah
  errors: { type: Object, default: () => ({}) },
  withActivate: Boolean,
})
const emit = defineEmits(['submit'])

const pad = (n) => String(n).padStart(2, '0')
const dateOf = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
const timeOf = (d) => `${pad(d.getHours())}:${pad(d.getMinutes())}`
const start0 = props.initial ? new Date(props.initial.starts_at) : (() => { const d = new Date(); d.setMinutes(0, 0, 0); d.setHours(d.getHours() + 1); return d })()
const end0 = props.initial ? new Date(props.initial.ends_at) : new Date(start0.getTime() + 8 * 3600000)

const f = reactive({
  name: props.initial?.name ?? '',
  location: props.initial?.location ?? '',
  startDate: dateOf(start0), startTime: timeOf(start0),
  endDate: dateOf(end0), endTime: timeOf(end0),
  show_on_profile: props.initial?.show_on_profile ?? true,
  activate: props.withActivate,
})
const starts = computed(() => new Date(`${f.startDate}T${f.startTime || '00:00'}`))
const ends = computed(() => new Date(`${f.endDate}T${f.endTime || '00:00'}`))
const hours = computed(() => (ends.value - starts.value) / 3600000)
const multiDay = computed(() => f.endDate !== f.startDate)
const durationText = computed(() => {
  const h = hours.value
  if (!(h > 0)) return null
  if (h < 24) return `${Number.isInteger(h) ? h : h.toFixed(1)} jam`
  const d = Math.floor(h / 24)
  const rest = Math.round(h % 24)
  return `${d} hari${rest ? ` ${rest} jam` : ''}`
})
const localError = computed(() => (hours.value <= 0 ? 'Waktu selesai harus setelah waktu mulai.' : null))

// mengubah tanggal mulai menggeser tanggal selesai dengan durasi yang sama
let lastStart = starts.value
watch(starts, (now) => {
  if (Number.isNaN(now.getTime()) || Number.isNaN(lastStart.getTime())) return
  const e = new Date(ends.value.getTime() + (now - lastStart))
  if (!Number.isNaN(e.getTime())) { f.endDate = dateOf(e); f.endTime = timeOf(e) }
  lastStart = now
})
const DURATIONS = [
  { label: '2 jam', h: 2 }, { label: 'Setengah hari', h: 4 }, { label: 'Seharian', h: 9 }, { label: '2 hari', h: 33 }, { label: '3 hari', h: 57 },
]
function setDuration(h) {
  const e = new Date(starts.value.getTime() + h * 3600000)
  f.endDate = dateOf(e)
  f.endTime = timeOf(e)
}
function submit() {
  if (localError.value || !f.name.trim()) return
  emit('submit', {
    name: f.name.trim(), location: f.location.trim() || null,
    starts_at: starts.value.toISOString(), ends_at: ends.value.toISOString(),
    show_on_profile: f.show_on_profile, ...(props.withActivate ? { activate: f.activate } : {}),
  })
}
const OPTIONS = computed(() => [
  { key: 'show_on_profile', icon: UserRoundCheck, title: 'Tampilkan di bio link', text: `Pengunjung melihat label kecil "Bertemu di ${f.name || 'event ini'}".` },
  ...(props.withActivate ? [{ key: 'activate', icon: Radio, title: 'Aktifkan Mode Event sekarang', text: 'Tap & koneksi baru langsung diberi label event ini sampai selesai.' }] : []),
])
</script>

<template>
  <form :id="id" class="grid gap-6" novalidate @submit.prevent="submit">
    <fieldset class="grid gap-4">
      <legend class="label mb-3">Info</legend>
      <AppField label="Nama event" :error="errors.name?.[0] || (!f.name.trim() && f.name !== '' ? 'Isi nama event.' : '')">
        <AppInput v-model="f.name" maxlength="120" required autofocus placeholder="mis. Expo UMKM Jakarta 2026" />
      </AppField>
      <AppField label="Lokasi (opsional)">
        <div class="relative">
          <MapPin class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
          <AppInput v-model="f.location" maxlength="160" placeholder="mis. JCC Senayan, Hall B" class="!pl-10" />
        </div>
      </AppField>
    </fieldset>

    <fieldset class="grid gap-4">
      <legend class="label mb-3">Waktu</legend>
      <div class="grid gap-3 sm:grid-cols-[1.2fr_1fr]">
        <AppField label="Mulai">
          <div class="relative"><CalendarDays class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted" aria-hidden="true" /><AppInput v-model="f.startDate" type="date" class="!pl-10" aria-label="Tanggal mulai" /></div>
        </AppField>
        <AppField label="Jam mulai">
          <div class="relative"><Clock class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted" aria-hidden="true" /><AppInput v-model="f.startTime" type="time" class="!pl-10" aria-label="Jam mulai" /></div>
        </AppField>
        <AppField label="Selesai" :error="errors.ends_at?.[0] || localError || ''">
          <div class="relative"><CalendarDays class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted" aria-hidden="true" /><AppInput v-model="f.endDate" type="date" :min="f.startDate" class="!pl-10" aria-label="Tanggal selesai" /></div>
        </AppField>
        <AppField label="Jam selesai">
          <div class="relative"><Clock class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted" aria-hidden="true" /><AppInput v-model="f.endTime" type="time" class="!pl-10" aria-label="Jam selesai" /></div>
        </AppField>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-sm text-muted">Durasi:</span>
        <button
          v-for="d in DURATIONS" :key="d.label" type="button"
          :aria-pressed="Math.abs(hours - d.h) < 0.01"
          class="h-8 cursor-pointer rounded-full border border-line px-3 text-sm hover:bg-surface-2 aria-pressed:border-accent aria-pressed:bg-accent aria-pressed:text-accent-ink"
          @click="setDuration(d.h)"
        >{{ d.label }}</button>
        <span v-if="durationText" class="ml-auto text-sm text-muted">{{ durationText }}{{ multiDay ? ' · beberapa hari' : '' }}</span>
      </div>
    </fieldset>

    <fieldset class="grid gap-3">
      <legend class="label mb-3">Opsi</legend>
      <label
        v-for="o in OPTIONS" :key="o.key"
        :class="['flex cursor-pointer items-start gap-3 rounded-md border p-4 transition', f[o.key] ? 'border-accent bg-surface-2' : 'border-line hover:bg-surface-2']"
      >
        <component :is="o.icon" class="mt-0.5 size-5 flex-none text-accent-text" aria-hidden="true" />
        <span class="grid min-w-0 flex-1 gap-0.5"><span class="font-medium">{{ o.title }}</span><span class="text-sm text-muted">{{ o.text }}</span></span>
        <span class="relative mt-0.5 inline-flex flex-none">
          <input v-model="f[o.key]" type="checkbox" role="switch" class="peer sr-only" />
          <span class="h-[26px] w-11 rounded-full border border-line-strong bg-surface-2 transition peer-checked:border-accent peer-checked:bg-accent peer-focus-visible:outline-2 peer-focus-visible:outline-accent"></span>
          <span class="absolute top-1 left-1 size-[18px] rounded-full bg-muted transition peer-checked:translate-x-[18px] peer-checked:bg-accent-ink"></span>
        </span>
      </label>
    </fieldset>
  </form>
</template>
