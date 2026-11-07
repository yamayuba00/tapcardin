<script setup>
// Keranjang & checkout cetak: tambah kartu, ID badge, & lanyard dari desain sendiri (satuan atau paket kartu + lanyard
// pasangannya) → centang item yang mau dipesan → alamat kirim lengkap (cek langsung per kolom) → kode promo → bayar QRIS.
// Harga di layar = ringkasan dari server (POST /api/orders/quote); harga final dihitung ulang saat pesanan dibuat.
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CircleAlert, CircleCheck, Link2, Minus, Package, Plus, QrCode, ShieldCheck, ShoppingCart, Tag, Trash2, Truck, X } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppCheckbox from '@/components/ui/AppCheckbox/index.vue'
import AppEmpty from '@/components/ui/AppEmpty/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import AppTextarea from '@/components/ui/AppTextarea/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import CardSide from '@/components/studio/CardSide/index.vue'
import LanyardStrip from '@/components/studio/LanyardStrip/index.vue'
import { cartApi, ordersApi } from '@/api/workspace'
import { useAction, useAsync } from '@/composables/useAsync'
import { rupiah } from '@/lib/format'
import { useToastStore } from '@/stores/toast'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()
const { data: cat } = useAsync(() => ordersApi.catalog())
const { busy, run } = useAction()

// ── keranjang ──────────────────────────────────────────────
const cart = ref(null)
const selected = ref(new Set()) // id item keranjang yang ikut dipesan
const working = ref(false)
async function cartCall(fn, ok) {
  working.value = true
  try {
    const res = await fn()
    cart.value = res.data
    if (ok) toast.ok(ok)
    return res
  } catch (e) {
    toast.error(e.message)
    return null
  } finally {
    working.value = false
  }
}
function setCart(items, select = []) {
  cart.value = items
  const ids = new Set(items.map((i) => i.id))
  selected.value = new Set([...selected.value].filter((id) => ids.has(id)).concat(select))
}
async function loadCart() {
  const res = await cartApi.list()
  setCart(res.data, res.data.map((i) => i.id)) // semua item tercentang saat halaman dibuka
}
loadCart()

async function addToCart(d, withLanyard = false) {
  const before = new Set((cart.value ?? []).map((i) => i.id))
  const res = await cartCall(() => cartApi.add({ design: d.id, with_lanyard: withLanyard }), withLanyard ? 'Kartu + lanyard pasangan masuk keranjang' : 'Masuk keranjang')
  if (res) setCart(res.data, res.data.filter((i) => !before.has(i.id) || i.id === res.meta?.added).map((i) => i.id))
}
const changeItem = (it, body) => cartCall(() => cartApi.update(it.id, body)).then((r) => r && setCart(r.data))
const removeItem = (it) => cartCall(() => cartApi.remove(it.id), 'Dihapus dari keranjang').then((r) => r && setCart(r.data))
function stepQty(it, dir) {
  const by = it.kind === 'card' ? 1 : 10
  const q = Math.max(it.min_qty, Math.min(5000, it.quantity + dir * by))
  if (q !== it.quantity) changeItem(it, { quantity: q })
}
function typedQty(it, e) {
  const q = Math.max(it.min_qty, Math.min(5000, Number(e.target.value) || it.min_qty))
  e.target.value = q
  if (q !== it.quantity) changeItem(it, { quantity: q })
}
function toggle(it, on) {
  const s = new Set(selected.value)
  on ? s.add(it.id) : s.delete(it.id)
  selected.value = s
}
const chosen = computed(() => (cart.value ?? []).filter((i) => selected.value.has(i.id)))
const productOptions = (it) => (cat.value?.card_products ?? [])
  .filter((p) => p.form_factor === 'card' || (it.design.orientation === 'portrait' && it.design.lanyard))
  .map((p) => ({ value: p.key, label: `${p.name} · ${rupiah(p.price)}`, description: p.form_factor === 'id_badge' ? 'Potret, lubang lanyard' : p.description }))
const kindLabel = (it) => (it.kind === 'lanyard' ? 'Lanyard' : it.form_factor === 'id_badge' ? 'ID Badge' : 'Kartu')

// ── desain yang bisa ditambahkan ───────────────────────────
const tab = ref('card')
const designs = computed(() => cat.value?.designs ?? [])
const isBadge = (d) => d.kind === 'card' && d.orientation === 'portrait' && d.has_slot
const lists = computed(() => ({
  card: designs.value.filter((d) => d.kind === 'card' && !isBadge(d)),
  badge: designs.value.filter(isBadge),
  lanyard: designs.value.filter((d) => d.kind === 'lanyard'),
}))
const TABS = computed(() => [
  { value: 'card', label: `Kartu (${lists.value.card.length})` },
  { value: 'badge', label: `ID Badge (${lists.value.badge.length})` },
  { value: 'lanyard', label: `Lanyard (${lists.value.lanyard.length})` },
])
const inCart = (d) => (cart.value ?? []).some((i) => i.design.id === d.id)

// desain dari tombol "Pesan cetak" (?design=) langsung masuk keranjang
watch([cat, cart], ([c, items]) => {
  const id = String(route.query.design || '')
  if (!c || !items || !id) return
  const d = c.designs.find((x) => x.id === id)
  router.replace({ query: {} })
  if (d?.ready && !inCart(d)) addToCart(d)
  if (d) tab.value = d.kind === 'lanyard' ? 'lanyard' : isBadge(d) ? 'badge' : 'card'
})

// ── pengiriman ─────────────────────────────────────────────
const ship = reactive({ method: '', recipient_name: '', recipient_phone: '', province: '', city: '', district: '', address_line: '', postal_code: '', notes: '' })
const touched = reactive({})
const serverErrors = ref({})
const method = computed(() => cat.value?.shipping.find((m) => m.key === ship.method))
watch(cat, (c) => {
  if (!c) return
  ship.method = c.shipping.find((m) => m.needs_address)?.key ?? c.shipping[0]?.key ?? ''
  if (c.last_address) Object.assign(ship, c.last_address)
}, { once: true })
const provinceOptions = computed(() => (cat.value?.provinces ?? []).map((p) => ({ value: p, label: p })))
const RULES = {
  recipient_name: (v) => (!v.trim() ? 'Isi nama penerima.' : ''),
  recipient_phone: (v) => (!v.trim() ? 'Isi nomor HP penerima.' : !/^(\+62|62|0)8[0-9\s-]{7,14}$/.test(v.trim()) ? 'Nomor HP belum valid, mis. 0812 3456 7890.' : ''),
  province: (v) => (!v ? 'Pilih provinsi.' : ''),
  city: (v) => (!v.trim() ? 'Isi kota/kabupaten.' : ''),
  district: (v) => (!v.trim() ? 'Isi kecamatan.' : ''),
  address_line: (v) => (!v.trim() ? 'Isi alamat lengkap.' : v.trim().length < 10 ? 'Tulis lebih lengkap: jalan, nomor, RT/RW, patokan.' : ''),
  postal_code: (v) => (!v.trim() ? 'Isi kode pos.' : !/^[0-9]{5}$/.test(v.trim()) ? 'Kode pos 5 angka.' : ''),
}
const localErrors = computed(() => (method.value?.needs_address ? Object.fromEntries(Object.entries(RULES).map(([k, f]) => [k, f(ship[k] ?? '')]).filter(([, m]) => m)) : {}))
// galat tampil setelah kolom disentuh (atau setelah mencoba bayar); galat server selalu tampil
const err = (k) => serverErrors.value[`shipping.${k}`]?.[0] ?? (touched[k] ? localErrors.value[k] : '')
const ok = (k) => touched[k] && !localErrors.value[k] && !serverErrors.value[`shipping.${k}`]
const missing = computed(() => Object.keys(localErrors.value).length)
watch(() => ({ ...ship }), () => (serverErrors.value = {}), { deep: true })
const filledRequired = computed(() => Object.keys(RULES).filter((k) => !RULES[k](ship[k] ?? '')).length)

// ── promo & ringkasan harga (server) ───────────────────────
const promo = ref('')
const appliedPromo = ref('')
const promoError = ref('')
const quote = ref(null)
const quoting = ref(false)
const payload = computed(() => chosen.value.map((i) => ({ kind: i.kind, design: i.design.id, product: i.product, quantity: i.quantity })))
let qt = null
async function refreshQuote() {
  if (!payload.value.length || !ship.method) return (quote.value = null)
  quoting.value = true
  try {
    quote.value = (await ordersApi.quote({ items: payload.value, method: ship.method, promo: appliedPromo.value || null })).data
  } catch (e) {
    if (e.fields?.promo) {
      promoError.value = e.fields.promo[0]
      appliedPromo.value = ''
      return refreshQuote()
    }
    quote.value = null
    toast.error(e.message)
  } finally {
    quoting.value = false
  }
}
watch([payload, () => ship.method, appliedPromo], () => { clearTimeout(qt); qt = setTimeout(refreshQuote, 250) }, { deep: true })
function applyPromo() {
  promoError.value = ''
  appliedPromo.value = promo.value.trim().toUpperCase()
}
function clearPromo() {
  promo.value = ''
  appliedPromo.value = ''
  promoError.value = ''
}

// ── bayar ──────────────────────────────────────────────────
async function submit() {
  for (const k of Object.keys(RULES)) touched[k] = true
  if (!chosen.value.length) return toast.error('Centang minimal satu item keranjang untuk dipesan.')
  if (missing.value) return toast.error(`Lengkapi ${missing.value} kolom pengiriman yang ditandai merah.`)
  serverErrors.value = {}
  const res = await run(() => ordersApi.create({
    items: payload.value, shipping: { ...ship }, promo: appliedPromo.value || null, cart: chosen.value.map((i) => i.id),
  })).catch((e) => {
    serverErrors.value = e.fields ?? {}
    if (e.fields?.promo) promoError.value = e.fields.promo[0]
    return null
  })
  if (!res) return
  const u = new URL(res.data.payment_url, window.location.href)
  if (u.origin === window.location.origin) router.push(u.pathname + u.search)
  else window.location.assign(res.data.payment_url)
}
</script>

<template>
  <PageHeader eyebrow="Pesanan" title="Keranjang & checkout" back="/orders" back-label="Pesanan" />
  <AppSkeleton v-if="!cat || !cart" class="!h-96" />

  <AppEmpty v-else-if="!designs.length" text="Belum ada desain. Buat desain kartu, ID badge, atau lanyard dulu di Design Studio, lalu kembali ke sini untuk memesan.">
    <AppButton to="/designs">Buka Design Studio</AppButton>
  </AppEmpty>

  <div v-else class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
    <div class="grid min-w-0 gap-6">
      <!-- 1. tambah ke keranjang -->
      <AppPanel title="1 · Tambah ke keranjang">
        <div class="grid gap-4">
          <AppSegmented v-model="tab" label="Jenis produk" :options="TABS" />
          <p v-if="tab === 'badge'" class="text-sm text-muted">ID Badge = desain kartu <b class="text-fg">potret dengan lubang lanyard</b>. Buat di Design Studio: kartu baru potret, lalu aktifkan "Kartu dipakai dengan lanyard".</p>
          <p v-if="!lists[tab].length" class="rounded-md border border-dashed border-line-strong p-4 text-sm text-muted">
            Belum ada desain {{ { card: 'kartu', badge: 'ID badge', lanyard: 'lanyard' }[tab] }}. <RouterLink to="/designs">Buat di Design Studio</RouterLink>.
          </p>
          <div v-else :class="['grid gap-3', tab === 'lanyard' ? 'sm:grid-cols-2' : 'grid-cols-2 sm:grid-cols-3']">
            <article v-for="d in lists[tab]" :key="d.id" :class="['grid content-start gap-2 rounded-md border p-2 text-sm', inCart(d) ? 'border-accent bg-surface-2' : 'border-line']">
              <div v-if="d.kind === 'card'" class="overflow-hidden rounded-sm" :class="d.orientation === 'portrait' ? 'mx-auto w-1/2' : ''"><CardSide :side="d.front" :kit="d.kit" :qr="cat.qr" trim /></div>
              <div v-else class="overflow-hidden rounded-sm"><LanyardStrip :side="d.front" :kit="d.kit" :length="200" :scale="1.4" /></div>
              <span class="truncate font-medium">{{ d.name }}</span>
              <AppBadge v-if="!d.ready" tone="warn">Perlu cek cetak</AppBadge>
              <template v-else>
                <AppButton variant="secondary" :disabled="working" @click="addToCart(d)"><ShoppingCart class="size-4" /> {{ inCart(d) ? 'Tambah lagi' : 'Ke keranjang' }}</AppButton>
                <AppButton v-if="d.kind === 'card' && d.paired_lanyard" variant="ghost" :disabled="working" @click="addToCart(d, true)"><Link2 class="size-4" /> + Lanyard pasangan</AppButton>
              </template>
            </article>
          </div>
          <p class="text-xs text-muted">Desain bertanda <b>Perlu cek cetak</b> belum bisa dipesan — buka di <RouterLink to="/designs">Design Studio</RouterLink>, perbaiki di tab <i>Cek cetak</i>, lalu simpan. Nama & kontak di kartu diambil dari bio Anda saat pesanan dibuat.</p>
        </div>
      </AppPanel>

      <!-- 2. keranjang -->
      <AppPanel :title="`2 · Keranjang (${cart.length})`">
        <p v-if="!cart.length" class="flex items-center gap-2 text-sm text-muted"><ShoppingCart class="size-5" /> Keranjang kosong. Tambahkan desain dari langkah 1.</p>
        <ul v-else class="grid gap-3">
          <li v-for="it in cart" :key="it.id" :class="['grid gap-3 rounded-md border p-3', selected.has(it.id) ? 'border-accent' : 'border-line opacity-80']">
            <div class="flex items-start gap-3">
              <AppCheckbox :model-value="selected.has(it.id)" :aria-label="`Pesan ${it.design.name}`" @update:model-value="(v) => toggle(it, v)" />
              <div class="w-16 flex-none overflow-hidden rounded-sm sm:w-20">
                <CardSide v-if="it.kind === 'card'" :side="it.design.front" :kit="it.design.kit" :qr="cat.qr" trim />
                <LanyardStrip v-else :side="it.design.front" :kit="it.design.kit" :length="80" :scale="1" />
              </div>
              <div class="grid min-w-0 flex-1 gap-0.5">
                <span class="truncate font-medium">{{ it.design.name }}</span>
                <span class="text-sm text-muted">{{ kindLabel(it) }}<template v-if="it.kind === 'lanyard'"> · {{ it.design.lanyard_spec?.width_mm }} mm · {{ it.design.lanyard_spec?.print_sides }} sisi · {{ it.design.lanyard_spec?.hardware }}</template> · {{ rupiah(it.unit_price) }}/pcs</span>
                <span v-if="it.design.paired" class="flex items-center gap-1 text-xs text-accent-text"><Link2 class="size-3.5" /> Pasangan: {{ it.design.paired.name }}</span>
              </div>
              <AppButton variant="ghost" icon :disabled="working" :aria-label="`Hapus ${it.design.name}`" @click="removeItem(it)"><Trash2 class="size-4" /></AppButton>
            </div>
            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
              <AppSelect v-if="it.kind === 'card'" :model-value="it.product" :options="productOptions(it)" :searchable="false" :aria-label="`Jenis ${it.design.name}`" @update:model-value="(v) => changeItem(it, { product: v })" />
              <span v-else class="text-sm text-muted">min. {{ it.min_qty }} pcs</span>
              <div class="flex items-center justify-between gap-3">
                <div class="flex items-center rounded-md border border-line-strong">
                  <AppButton variant="ghost" icon aria-label="Kurangi" :disabled="working || it.quantity <= it.min_qty" @click="stepQty(it, -1)"><Minus class="size-4" /></AppButton>
                  <input :value="it.quantity" type="number" inputmode="numeric" :min="it.min_qty" max="5000" class="w-16 bg-transparent text-center font-mono outline-none" :aria-label="`Jumlah ${it.design.name}`" @change="typedQty(it, $event)" />
                  <AppButton variant="ghost" icon aria-label="Tambah" :disabled="working" @click="stepQty(it, 1)"><Plus class="size-4" /></AppButton>
                </div>
                <span class="font-mono">{{ rupiah(it.unit_price * it.quantity) }}</span>
              </div>
            </div>
          </li>
        </ul>
      </AppPanel>

      <!-- 3. pengiriman -->
      <AppPanel title="3 · Pengiriman">
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
            <p class="flex items-center gap-2 text-sm text-muted">
              Semua kolom bertanda * wajib diisi agar kiriman sampai.
              <span :class="['ml-auto font-mono text-xs', missing ? 'text-warn' : 'text-ok']">{{ filledRequired }}/7 lengkap</span>
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
              <AppField label="Nama penerima *" :error="err('recipient_name')"><AppInput v-model="ship.recipient_name" autocomplete="name" maxlength="120" :invalid="!!err('recipient_name')" @blur="touched.recipient_name = true" /></AppField>
              <AppField label="No. HP penerima *" :error="err('recipient_phone')" :hint="ok('recipient_phone') ? 'Nomor valid' : 'Dipakai kurir untuk menghubungi'">
                <AppInput v-model="ship.recipient_phone" type="tel" inputmode="tel" autocomplete="tel" maxlength="20" placeholder="0812…" :invalid="!!err('recipient_phone')" @blur="touched.recipient_phone = true" />
              </AppField>
              <AppField label="Provinsi *" :error="err('province')">
                <AppSelect v-model="ship.province" :options="provinceOptions" searchable placeholder="Pilih provinsi" :invalid="!!err('province')" aria-label="Provinsi" @update:model-value="touched.province = true" />
              </AppField>
              <AppField label="Kota / kabupaten *" :error="err('city')"><AppInput v-model="ship.city" maxlength="80" autocomplete="address-level2" placeholder="mis. Kota Bandung" :invalid="!!err('city')" @blur="touched.city = true" /></AppField>
              <AppField label="Kecamatan *" :error="err('district')"><AppInput v-model="ship.district" maxlength="80" autocomplete="address-level3" placeholder="mis. Coblong" :invalid="!!err('district')" @blur="touched.district = true" /></AppField>
              <AppField label="Kode pos *" :error="err('postal_code')"><AppInput v-model="ship.postal_code" inputmode="numeric" maxlength="5" autocomplete="postal-code" placeholder="40135" :invalid="!!err('postal_code')" @blur="touched.postal_code = true" /></AppField>
            </div>
            <AppField label="Alamat lengkap *" :error="err('address_line')" :hint="err('address_line') ? '' : `${ship.address_line.length}/300 · nama jalan, nomor rumah, RT/RW, kelurahan, patokan`">
              <AppTextarea v-model="ship.address_line" rows="2" maxlength="300" autocomplete="street-address" placeholder="Jl. Dago No. 10, RT 01/RW 02, Kel. Dago — dekat masjid" :invalid="!!err('address_line')" @blur="touched.address_line = true" />
            </AppField>
          </template>
          <p v-else class="text-sm text-muted">{{ method?.name }}: kami kabari lewat notifikasi & email saat pesanan siap diambil.</p>
          <AppField label="Catatan untuk kurir / tim (opsional)"><AppInput v-model="ship.notes" maxlength="300" placeholder="mis. titip di resepsionis" /></AppField>
        </div>
      </AppPanel>
    </div>

    <!-- ringkasan & bayar -->
    <aside class="lg:sticky lg:top-0">
      <AppPanel title="Ringkasan">
        <div class="grid gap-4">
          <p v-if="!chosen.length" class="flex items-center gap-2 text-sm text-muted"><Package class="size-5" /> Centang item keranjang yang mau dipesan.</p>
          <template v-else>
            <dl class="grid gap-2 text-sm" :aria-busy="quoting">
              <div v-for="it in chosen" :key="it.id" class="flex justify-between gap-3"><dt class="min-w-0 truncate">{{ it.quantity }} × {{ kindLabel(it) }} · {{ it.design.name }}</dt><dd class="font-mono">{{ rupiah(it.unit_price * it.quantity) }}</dd></div>
              <div class="flex justify-between gap-3 border-t border-line pt-2"><dt class="text-muted">Subtotal</dt><dd class="font-mono">{{ rupiah(quote?.subtotal ?? 0) }}</dd></div>
              <div class="flex justify-between gap-3 text-muted"><dt class="flex items-center gap-1"><Truck class="size-4" /> {{ method?.name ?? 'Pengiriman' }}</dt><dd class="font-mono">{{ quote?.shipping ? rupiah(quote.shipping) : 'Gratis' }}</dd></div>
              <div v-if="quote?.discount" class="flex justify-between gap-3 text-ok"><dt class="flex items-center gap-1"><Tag class="size-4" /> Promo {{ quote.promo?.code }}</dt><dd class="font-mono">−{{ rupiah(quote.discount) }}</dd></div>
              <div class="flex justify-between gap-3 border-t border-line pt-2 text-base font-semibold"><dt>Total</dt><dd class="font-display text-xl">{{ quote ? rupiah(quote.total) : '…' }}</dd></div>
            </dl>

            <!-- kode promo -->
            <div v-if="quote?.promo" class="flex items-center gap-2 rounded-md border border-line bg-surface-2 px-3 py-2 text-sm">
              <CircleCheck class="size-5 flex-none text-ok" />
              <span class="min-w-0 flex-1"><b class="font-mono">{{ quote.promo.code }}</b> dipakai<span v-if="quote.promo.description" class="block text-xs text-muted">{{ quote.promo.description }}</span></span>
              <AppButton variant="ghost" icon aria-label="Lepas promo" @click="clearPromo"><X class="size-4" /></AppButton>
            </div>
            <form v-else class="grid gap-1" @submit.prevent="applyPromo">
              <span class="text-sm font-medium text-muted">Kode promo</span>
              <div class="flex gap-2">
                <AppInput v-model="promo" maxlength="32" placeholder="Punya kode promo?" class="min-w-0 flex-1 font-mono uppercase" :invalid="!!promoError" aria-label="Kode promo" @input="promoError = ''" />
                <AppButton type="submit" variant="secondary" :disabled="!promo.trim() || quoting">Pakai</AppButton>
              </div>
              <span v-if="promoError" class="flex items-center gap-1 text-sm text-danger"><CircleAlert class="size-4" /> {{ promoError }}</span>
            </form>
          </template>

          <div class="grid gap-1 rounded-md border border-line p-3 text-sm">
            <span class="flex items-center gap-2 font-medium"><QrCode class="size-5 text-accent-text" /> Pembayaran: QRIS</span>
            <span class="text-muted">Scan dari aplikasi e-wallet (GoPay, OVO, DANA, ShopeePay) atau m-banking mana pun.</span>
          </div>
          <p v-if="!cat.payment" class="text-sm text-warn">Pembayaran online sedang disiapkan — pesanan belum bisa dibayar.</p>
          <p v-else-if="chosen.length && method?.needs_address && missing" class="flex items-start gap-2 text-sm text-warn"><CircleAlert class="mt-0.5 size-4 flex-none" /> Lengkapi {{ missing }} kolom pengiriman dulu.</p>
          <AppButton block size="lg" :loading="busy" :disabled="!chosen.length || !cat.payment || !quote" @click="submit">
            <QrCode class="size-5" /> Bayar {{ quote ? rupiah(quote.total) : '' }} dengan QRIS
          </AppButton>
          <p class="flex items-start gap-2 text-xs text-muted">
            <ShieldCheck class="mt-0.5 size-4 flex-none text-ok" />
            <span>Item yang tidak dicentang tetap tersimpan di keranjang. Produksi dimulai setelah pembayaran terkonfirmasi; kartu bisa langsung diaktifkan begitu tiba.</span>
          </p>
        </div>
      </AppPanel>
    </aside>
  </div>
</template>
