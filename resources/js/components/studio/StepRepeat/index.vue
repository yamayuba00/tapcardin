<script setup>
// Dialog "Ulangi berpola": salin elemen terpilih menjadi baris × kolom dengan jarak tetap (mis. pola logo lanyard).
import { computed, reactive } from 'vue'
import { Grid2x2Plus } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'

const props = defineProps({ count: { type: Number, required: true }, room: { type: Number, required: true } }) // room = sisa slot layer
const emit = defineEmits(['apply', 'close'])
const form = reactive({ rows: 1, cols: 3, gapX: 2, gapY: 2 })
const clampInt = (v, min, max) => Math.min(max, Math.max(min, Math.round(Number(v) || min)))
const total = computed(() => (clampInt(form.rows, 1, 20) * clampInt(form.cols, 1, 20) - 1) * props.count)
function apply() {
  emit('apply', { rows: clampInt(form.rows, 1, 20), cols: clampInt(form.cols, 1, 20), gapX: Number(form.gapX) || 0, gapY: Number(form.gapY) || 0 })
}
</script>

<template>
  <AppModal size="sm" title="Ulangi berpola" description="Salin elemen terpilih menjadi beberapa baris & kolom dengan jarak yang sama. Hasilnya satu grup." @close="emit('close')">
    <form id="step-repeat" class="grid gap-4" @submit.prevent="apply">
      <div class="grid grid-cols-2 gap-3">
        <AppField label="Baris"><AppInput v-model="form.rows" type="number" min="1" max="20" step="1" /></AppField>
        <AppField label="Kolom"><AppInput v-model="form.cols" type="number" min="1" max="20" step="1" /></AppField>
        <AppField label="Jarak kolom (mm)"><AppInput v-model="form.gapX" type="number" step="0.5" /></AppField>
        <AppField label="Jarak baris (mm)"><AppInput v-model="form.gapY" type="number" step="0.5" /></AppField>
      </div>
      <p :class="['text-sm', total > room ? 'text-warn' : 'text-muted']">
        {{ total > room ? `Hanya ${room} elemen lagi yang muat (batas 80 per sisi) — sisanya tidak dibuat.` : `Menambah ${total} elemen.` }}
      </p>
    </form>
    <template #footer>
      <AppButton variant="secondary" @click="emit('close')">Batal</AppButton>
      <AppButton type="submit" form="step-repeat" :disabled="total < 1 || room < 1"><Grid2x2Plus class="size-5" /> Buat pola</AppButton>
    </template>
  </AppModal>
</template>
