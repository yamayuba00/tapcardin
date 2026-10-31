<script setup>
// Spesifikasi & harga lanyard dari katalog server (lanyard_widths, lanyard_hardware): lebar tali, pengait,
// cetak 1/2 sisi, panjang pola berulang, jumlah → perkiraan harga (dihitung server). Checkout menyusul modul pesanan.
import { computed, ref, watch } from 'vue'
import { ShoppingCart } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import { studioApi } from '@/api/studio'

const props = defineProps({
  spec: { type: Object, required: true }, // { width_mm, hardware, print_sides, tile_mm }
  catalog: { type: Object, required: true }, // catalog.lanyard
})
const emit = defineEmits(['change'])
const qty = ref(50)
const quote = ref(null)
const rupiah = (n) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n ?? 0)
const width = computed(() => props.catalog.widths.find((w) => w.width_mm === props.spec.width_mm))

let t = null
watch(() => [props.spec.width_mm, props.spec.hardware, props.spec.print_sides, qty.value], () => {
  clearTimeout(t)
  t = setTimeout(async () => {
    if (!props.spec.width_mm || !props.spec.hardware || !(qty.value > 0)) return
    quote.value = (await studioApi.lanyardQuote({ width_mm: props.spec.width_mm, hardware: props.spec.hardware, print_sides: props.spec.print_sides, qty: qty.value }).catch(() => ({ data: null }))).data
  }, 250)
}, { immediate: true })
</script>

<template>
  <div class="grid gap-5">
    <div class="grid gap-2">
      <span class="text-sm font-medium text-muted">Lebar tali</span>
      <AppSegmented :model-value="spec.width_mm" label="Lebar tali" :options="catalog.widths.map((w) => ({ value: w.width_mm, label: `${w.width_mm} mm` }))" @update:model-value="(v) => emit('change', { width_mm: v })" />
      <span v-if="width" class="text-xs text-muted">{{ width.label }} · panjang {{ catalog.length_mm / 10 }} cm · min. {{ width.min_qty }} pcs</span>
    </div>

    <div class="grid gap-2">
      <span class="text-sm font-medium text-muted">Pengait</span>
      <div class="grid gap-2">
        <button
          v-for="h in catalog.hardware" :key="h.key" type="button" :aria-pressed="spec.hardware === h.key"
          class="flex cursor-pointer items-start justify-between gap-3 rounded-md border border-line p-3 text-left hover:bg-surface-2 aria-pressed:border-accent aria-pressed:bg-surface-2"
          @click="emit('change', { hardware: h.key })"
        >
          <span class="grid gap-0.5"><span class="text-sm font-medium">{{ h.name }}</span><span class="text-xs text-muted">{{ h.description }}</span></span>
          <span class="flex-none font-mono text-xs text-muted">{{ h.price ? `+${rupiah(h.price)}` : 'termasuk' }}</span>
        </button>
      </div>
    </div>

    <div class="grid gap-2">
      <span class="text-sm font-medium text-muted">Sisi cetak</span>
      <AppSegmented :model-value="spec.print_sides" label="Sisi cetak" :options="[{ value: 2, label: '2 sisi (depan & belakang)' }, { value: 1, label: '1 sisi' }]" @update:model-value="(v) => emit('change', { print_sides: v })" />
    </div>

    <AppField :label="`Panjang pola berulang: ${spec.tile_mm} mm`" :hint="`Pola diulang ${Math.ceil(catalog.length_mm / spec.tile_mm)}× sepanjang tali.`">
      <input type="range" :min="catalog.tile.min" :max="catalog.tile.max" step="10" :value="spec.tile_mm" class="w-full cursor-pointer accent-[var(--accent)]" aria-label="Panjang pola" @change="(e) => emit('change', { tile_mm: Number(e.target.value) })" />
    </AppField>

    <div class="grid gap-3 rounded-md border border-line bg-surface-2 p-4">
      <AppField label="Jumlah (pcs)"><AppInput v-model.number="qty" type="number" min="1" step="10" /></AppField>
      <template v-if="quote">
        <ul class="grid gap-1 text-sm">
          <li v-for="b in quote.breakdown" :key="b.label" class="flex justify-between gap-3"><span class="text-muted">{{ b.label }}</span><span class="font-mono">{{ rupiah(b.amount) }}</span></li>
          <li class="flex justify-between gap-3 border-t border-line pt-1"><span>Per pcs</span><span class="font-mono">{{ rupiah(quote.unit) }}</span></li>
        </ul>
        <p class="flex items-baseline justify-between gap-3"><span class="text-sm">Total {{ quote.qty }} pcs</span><span class="font-display text-2xl font-bold">{{ rupiah(quote.total) }}</span></p>
        <p v-if="quote.qty > qty" class="text-xs text-warn">Pesanan minimal {{ quote.min_qty }} pcs.</p>
      </template>
      <AppButton block disabled title="Pemesanan & pembayaran sedang disiapkan"><ShoppingCart class="size-5" /> Pesan — segera hadir</AppButton>
      <p class="text-xs text-muted">Harga perkiraan, belum termasuk ongkir. Pemesanan online menyusul bersama modul pesanan & pembayaran.</p>
    </div>
  </div>
</template>
