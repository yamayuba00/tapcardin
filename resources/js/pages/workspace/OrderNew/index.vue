<script setup>
// Pesan kartu fisik & lanyard dari desain sendiri: pilih desain (harus siap cetak) → jenis kartu & jumlah →
// pengiriman → ringkasan → bayar lewat payment service. Harga di sini pratinjau; harga final dihitung server.
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CircleAlert, Minus, Package, Plus, ShieldCheck, Trash2, Truck } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppEmpty from '@/components/ui/AppEmpty/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import AppTextarea from '@/components/ui/AppTextarea/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import CardSide from '@/components/studio/CardSide/index.vue'
import LanyardStrip from '@/components/studio/LanyardStrip/index.vue'
import { ordersApi } from '@/api/workspace'
import { useAction, useAsync } from '@/composables/useAsync'
import { rupiah } from '@/lib/format'

const route = useRoute()
const router = useRouter()
const { data: cat } = useAsync(() => ordersApi.catalog())
const { busy, run } = useAction()

const items = ref([]) // { design, kind, product?, quantity }
const ship = reactive({ method: '', recipient_name: '', recipient_phone: '', address_line: '', city: '', province: '', postal_code: '', notes: '' })
const errors = ref({})
const designs = computed(() => cat.value?.designs ?? [])
const cards = computed(() => designs.value.filter((d) => d.kind === 'card'))
const lanyards = computed(() => designs.value.filter((d) => d.kind === 'lanyard'))
const method = computed(() => cat.value?.shipping.find((m) => m.key === ship.method))
const byId = (id) => designs.value.find((d) => d.id === id)
const widthOf = (d) => cat.value?.lanyard.widths.find((w) => w.width_mm === d.lanyard?.width_mm)
const hardwareOf = (d) => cat.value?.lanyard.hardware.find((h) => h.key === d.lanyard?.hardware)

watch(cat, (c) => {
  if (!c) return
  ship.method = c.shipping[0]?.key ?? ''
  const pre = byId(String(route.query.design || ''))
  if (pre?.ready) add(pre)
}, { once: true })

function minQty(it) {
  const d = byId(it.design)
  if (it.kind === 'card') return cat.value.card_products.find((p) => p.key === it.product)?.min_qty ?? 1
  return widthOf(d)?.min_qty ?? 1
}
function unit(it) {
  const d = byId(it.design)
  if (it.kind === 'card') return cat.value.card_products.find((p) => p.key === it.product)?.price ?? 0
  const w = widthOf(d)
  return w ? (d.lanyard.print_sides === 1 ? w.price_single : w.price_double) + (hardwareOf(d)?.price ?? 0) : 0
}
function add(d) {
  if (!d.ready || items.value.some((i) => i.design === d.id)) return
  const it = { design: d.id, kind: d.kind, product: d.kind === 'card' ? cat.value.card_products[0]?.key : null, quantity: 1 }
  it.quantity = Math.max(d.kind === 'card' ? 1 : 10, minQty(it))
  items.value.push(it)
}
const remove = (i) => items.value.splice(i, 1)
const step = (it, by) => (it.quantity = Math.max(minQty(it), Math.min(5000, it.quantity + by)))
const picked = (d) => items.value.some((i) => i.design === d.id)

const subtotal = computed(() => items.value.reduce((s, it) => s + unit(it) * it.quantity, 0))
const total = computed(() => subtotal.value + (method.value?.price ?? 0))
const lowQty = computed(() => items.value.find((it) => it.quantity < minQty(it)))

async function submit() {
  errors.value = {}
  const res = await run(() => ordersApi.create({
    items: items.value.map(({ kind, design, product, quantity }) => ({ kind, design, product, quantity })),
    shipping: { ...ship },
  })).catch((e) => {
    errors.value = e.fields ?? (e.data?.error?.field ? { [`shipping.${e.data.error.field}`]: [e.message] } : {})
    return null
  })
  if (!res) return
  const u = new URL(res.data.payment_url, window.location.href)
  if (u.origin === window.location.origin) router.push(u.pathname + u.search)
  else window.location.assign(res.data.payment_url)
}
const err = (k) => errors.value[`shipping.${k}`]?.[0]
</script>

<template>
  <PageHeader eyebrow="Pesanan" title="Pesan kartu & lanyard" back="/orders" back-label="Pesanan" />
  <AppSkeleton v-if="!cat" class="!h-96" />

  <AppEmpty v-else-if="!designs.length" text="Belum ada desain. Buat desain kartu (atau lanyard) dulu di Design Studio, lalu kembali ke sini untuk memesan.">
    <AppButton to="/designs">Buka Design Studio</AppButton>
  </AppEmpty>

  <div v-else class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start">
    <div class="grid gap-6">
      <!-- 1. desain -->
      <AppPanel title="1 · Pilih desain">
        <div class="grid gap-5">
          <section v-if="cards.length" class="grid gap-2">
            <h3 class="label">Kartu</h3>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
              <button
                v-for="d in cards" :key="d.id" type="button" :disabled="!d.ready" :aria-pressed="picked(d)"
                class="grid cursor-pointer content-start gap-2 rounded-md border p-2 text-left text-sm disabled:cursor-not-allowed"
                :class="picked(d) ? 'border-accent bg-surface-2' : 'border-line hover:border-line-strong'" @click="add(d)"
              >
                <div class="overflow-hidden rounded-sm" :class="d.orientation === 'portrait' ? 'mx-auto w-1/2' : ''"><CardSide :side="d.front" :kit="d.kit" :qr="cat.qr" trim /></div>
                <span class="truncate font-medium">{{ d.name }}</span>
                <AppBadge v-if="!d.ready" tone="warn">Perlu cek cetak</AppBadge>
                <span v-else-if="picked(d)" class="text-xs text-accent-text">Ditambahkan</span>
              </button>
            </div>
          </section>
          <section v-if="lanyards.length" class="grid gap-2">
            <h3 class="label">Lanyard</h3>
            <div class="grid gap-3 sm:grid-cols-2">
              <button
                v-for="d in lanyards" :key="d.id" type="button" :disabled="!d.ready" :aria-pressed="picked(d)"
                class="grid cursor-pointer content-start gap-2 rounded-md border p-2 text-left text-sm disabled:cursor-not-allowed"
                :class="picked(d) ? 'border-accent bg-surface-2' : 'border-line hover:border-line-strong'" @click="add(d)"
              >
                <div class="overflow-hidden rounded-sm"><LanyardStrip :side="d.front" :kit="d.kit" :length="200" :scale="1.4" /></div>
                <span class="truncate font-medium">{{ d.name }}</span>
                <span class="text-xs text-muted">{{ d.lanyard?.width_mm }} mm · {{ d.lanyard?.print_sides }} sisi · {{ hardwareOf(d)?.name }}</span>
                <AppBadge v-if="!d.ready" tone="warn">Perlu cek cetak</AppBadge>
              </button>
            </div>
          </section>
          <p class="text-sm text-muted">
            Desain bertanda <b>Perlu cek cetak</b> belum bisa dipesan — buka di <RouterLink to="/designs">Design Studio</RouterLink>, perbaiki di tab <i>Cek cetak</i>, lalu simpan.
            Data seperti nama & kontak di kartu diambil dari bio Anda saat pesanan dibuat.
          </p>
        </div>
      </AppPanel>

      <!-- 2. jenis & jumlah -->
      <AppPanel v-if="items.length" title="2 · Jenis & jumlah">
        <ul class="grid gap-4">
          <li v-for="(it, i) in items" :key="it.design" class="grid gap-3 border-b border-line pb-4 last:border-b-0 last:pb-0">
            <div class="flex items-start justify-between gap-3">
              <div class="grid">
                <span class="font-medium">{{ byId(it.design)?.name }}</span>
                <span class="text-sm text-muted">{{ it.kind === 'card' ? 'Kartu' : 'Lanyard' }} · {{ rupiah(unit(it)) }} / pcs</span>
              </div>
              <AppButton variant="ghost" icon :aria-label="`Hapus ${byId(it.design)?.name}`" @click="remove(i)"><Trash2 class="size-4" /></AppButton>
            </div>
            <div v-if="it.kind === 'card'" class="grid gap-2 sm:grid-cols-2">
              <button
                v-for="p in cat.card_products" :key="p.key" type="button" :aria-pressed="it.product === p.key"
                class="grid cursor-pointer gap-0.5 rounded-md border p-3 text-left"
                :class="it.product === p.key ? 'border-accent bg-surface-2' : 'border-line hover:border-line-strong'" @click="it.product = p.key; step(it, 0)"
              >
                <span class="flex justify-between gap-2 text-sm font-medium">{{ p.name }}<span class="font-mono">{{ rupiah(p.price) }}</span></span>
                <span class="text-xs text-muted">{{ p.description }}</span>
              </button>
            </div>
            <div class="flex flex-wrap items-center gap-3">
              <div class="flex items-center rounded-md border border-line-strong">
                <AppButton variant="ghost" icon aria-label="Kurangi" :disabled="it.quantity <= minQty(it)" @click="step(it, it.kind === 'card' ? -1 : -10)"><Minus class="size-4" /></AppButton>
                <input v-model.number="it.quantity" type="number" :min="minQty(it)" max="5000" class="w-20 bg-transparent text-center font-mono outline-none" :aria-label="`Jumlah ${byId(it.design)?.name}`" />
                <AppButton variant="ghost" icon aria-label="Tambah" @click="step(it, it.kind === 'card' ? 1 : 10)"><Plus class="size-4" /></AppButton>
              </div>
              <span class="text-sm text-muted">min. {{ minQty(it) }} pcs</span>
              <span class="ml-auto font-mono">{{ rupiah(unit(it) * it.quantity) }}</span>
            </div>
          </li>
        </ul>
      </AppPanel>

      <!-- 3. pengiriman -->
      <AppPanel v-if="items.length" title="3 · Pengiriman">
        <div class="grid gap-4">
          <div class="grid gap-2 sm:grid-cols-3">
            <button
              v-for="m in cat.shipping" :key="m.key" type="button" :aria-pressed="ship.method === m.key"
              class="grid cursor-pointer gap-0.5 rounded-md border p-3 text-left"
              :class="ship.method === m.key ? 'border-accent bg-surface-2' : 'border-line hover:border-line-strong'" @click="ship.method = m.key"
            >
              <span class="text-sm font-medium">{{ m.name }}</span>
              <span class="text-xs text-muted">{{ m.eta }}</span>
              <span class="font-mono text-sm">{{ m.price ? rupiah(m.price) : 'Gratis' }}</span>
            </button>
          </div>
          <template v-if="method?.needs_address">
            <div class="grid gap-4 sm:grid-cols-2">
              <AppField label="Nama penerima" :error="err('recipient_name')"><AppInput v-model="ship.recipient_name" autocomplete="name" maxlength="120" /></AppField>
              <AppField label="No. HP penerima" :error="err('recipient_phone')"><AppInput v-model="ship.recipient_phone" type="tel" autocomplete="tel" maxlength="32" /></AppField>
            </div>
            <AppField label="Alamat lengkap" :error="err('address_line')"><AppTextarea v-model="ship.address_line" rows="2" maxlength="300" autocomplete="street-address" placeholder="Nama jalan, nomor, RT/RW, kelurahan, kecamatan" /></AppField>
            <div class="grid gap-4 sm:grid-cols-3">
              <AppField label="Kota / kabupaten" :error="err('city')"><AppInput v-model="ship.city" maxlength="80" autocomplete="address-level2" /></AppField>
              <AppField label="Provinsi"><AppInput v-model="ship.province" maxlength="80" autocomplete="address-level1" /></AppField>
              <AppField label="Kode pos" :error="err('postal_code')"><AppInput v-model="ship.postal_code" inputmode="numeric" maxlength="5" autocomplete="postal-code" /></AppField>
            </div>
          </template>
          <AppField label="Catatan (opsional)"><AppInput v-model="ship.notes" maxlength="300" placeholder="mis. titip di resepsionis" /></AppField>
        </div>
      </AppPanel>
    </div>

    <!-- ringkasan -->
    <aside class="lg:sticky lg:top-0">
      <AppPanel title="Ringkasan">
        <div class="grid gap-4">
          <p v-if="!items.length" class="flex items-center gap-2 text-sm text-muted"><Package class="size-5" /> Pilih desain di kiri untuk mulai.</p>
          <dl v-else class="grid gap-2 text-sm">
            <div v-for="it in items" :key="it.design" class="flex justify-between gap-3"><dt class="min-w-0 truncate">{{ it.quantity }} × {{ byId(it.design)?.name }}</dt><dd class="font-mono">{{ rupiah(unit(it) * it.quantity) }}</dd></div>
            <div class="flex justify-between gap-3 text-muted"><dt class="flex items-center gap-1"><Truck class="size-4" /> {{ method?.name ?? 'Pengiriman' }}</dt><dd class="font-mono">{{ method?.price ? rupiah(method.price) : 'Gratis' }}</dd></div>
            <div class="flex justify-between gap-3 border-t border-line pt-2 text-base font-semibold"><dt>Total</dt><dd class="font-display text-xl">{{ rupiah(total) }}</dd></div>
          </dl>
          <p v-if="lowQty" class="flex items-start gap-2 text-sm text-warn"><CircleAlert class="mt-0.5 size-4 flex-none" /> Jumlah minimal {{ minQty(lowQty) }} pcs untuk {{ byId(lowQty.design)?.name }}.</p>
          <p v-if="!cat.payment" class="text-sm text-warn">Pembayaran online sedang disiapkan — pesanan belum bisa dibayar.</p>
          <AppButton block size="lg" :loading="busy" :disabled="!items.length || !!lowQty || !cat.payment" @click="submit">Pesan & bayar {{ items.length ? rupiah(total) : '' }}</AppButton>
          <p class="flex items-start gap-2 text-xs text-muted">
            <ShieldCheck class="mt-0.5 size-4 flex-none text-ok" />
            <span>Dibayar lewat {{ cat.payment?.name ?? 'gateway pembayaran' }}{{ cat.payment && !cat.payment.simulator ? ` (${cat.payment.method})` : '' }}. Produksi dimulai setelah pembayaran terkonfirmasi; kartu bisa langsung diaktifkan begitu tiba.</span>
          </p>
        </div>
      </AppPanel>
    </aside>
  </div>
</template>
