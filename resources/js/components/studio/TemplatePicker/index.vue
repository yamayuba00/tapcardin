<script setup>
// Pilih titik awal desain kartu: kartu kosong, template default, atau template milik sendiri; + orientasi & lanyard.
// Arahkan kursor ke kartu untuk melihat sisi belakang. Klik dua kali = langsung mulai.
import { computed, ref } from 'vue'
import { Check } from 'lucide-vue-next'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppToggle from '@/components/ui/AppToggle/index.vue'
import FlipCard from '@/components/studio/FlipCard/index.vue'

const props = defineProps({ catalog: { type: Object, required: true } })
const emit = defineEmits(['pick'])

const source = ref('default') // default | mine
const orientation = ref('landscape')
const lanyard = ref(false)
const selected = ref(props.catalog.templates[0]?.key)
const mine = ref(props.catalog.my_templates?.[0]?.id ?? null)

/** Pratinjau lanyard: tampilkan zona slot di atas pratinjau tanpa lanyard (posisi elemen final dari server). */
const preview = (t, side) => {
  const s = t.preview[orientation.value][side]
  if (!lanyard.value) return s
  const w = s.size.w
  const h = orientation.value === 'portrait' ? 12 : 10
  return { ...s, zones: { ...s.zones, slot: { x: (w - 16) / 2, y: -3, w: 16, h: h + 3 } } }
}
const choice = computed(() => (source.value === 'mine' && mine.value
  ? { from: mine.value }
  : { template: selected.value, orientation: orientation.value, lanyard: lanyard.value }))
defineExpose({ choice })
const card = 'group/tpl grid cursor-pointer gap-2 rounded-md border border-line p-3 text-left hover:bg-surface-2 aria-pressed:border-accent aria-pressed:bg-surface-2'
</script>

<template>
  <div class="grid gap-5">
    <div class="flex flex-wrap items-center gap-4">
      <AppSegmented v-if="catalog.my_templates?.length" v-model="source" label="Sumber" :options="[{ value: 'default', label: 'Template TapCard' }, { value: 'mine', label: `Template saya (${catalog.my_templates.length})` }]" />
      <template v-if="source === 'default'">
        <AppSegmented v-model="orientation" label="Orientasi" :options="[{ value: 'landscape', label: 'Lanskap · dompet' }, { value: 'portrait', label: 'Potret · ID badge' }]" />
        <AppToggle v-model="lanyard">Dipakai dengan lanyard (lubang slot di atas)</AppToggle>
      </template>
    </div>
    <p v-if="source === 'default' && lanyard" class="text-sm text-muted">Area ±{{ orientation === 'portrait' ? 12 : 10 }} mm di tengah atas dikosongkan untuk lubang slot. Teks & gambar tidak boleh masuk area ini.</p>

    <div v-if="source === 'default'" :class="['grid gap-4', orientation === 'portrait' ? 'grid-cols-2 sm:grid-cols-4' : 'grid-cols-1 sm:grid-cols-2']">
      <button
        v-for="t in catalog.templates" :key="t.key" type="button" :aria-pressed="selected === t.key" :class="card"
        @click="selected = t.key" @dblclick="emit('pick', { ...choice, template: t.key })"
      >
        <FlipCard :front="preview(t, 'front')" :back="preview(t, 'back')" :vars="catalog.variables" :qr="catalog.qr" hover-flip />
        <span class="flex items-center justify-between gap-2">
          <span class="font-medium">{{ t.name }}<span v-if="t.key === 'kosong'" class="ml-2 rounded-sm bg-surface-2 px-1.5 py-0.5 text-xs text-muted">dari nol</span></span>
          <Check v-if="selected === t.key" class="size-4 text-accent-text" />
        </span>
        <span class="text-xs text-muted">{{ t.description }}</span>
      </button>
    </div>
    <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2">
      <button
        v-for="t in catalog.my_templates" :key="t.id" type="button" :aria-pressed="mine === t.id" :class="card"
        @click="mine = t.id" @dblclick="emit('pick', { from: t.id })"
      >
        <div :class="t.orientation === 'portrait' ? 'mx-auto w-1/2' : ''"><FlipCard :front="t.front" :back="t.back" :vars="catalog.variables" :qr="catalog.qr" hover-flip /></div>
        <span class="flex items-center justify-between gap-2"><span class="font-medium">{{ t.name }}</span><Check v-if="mine === t.id" class="size-4 text-accent-text" /></span>
      </button>
    </div>
  </div>
</template>
