<script setup>
// Landing page (domain utama). Urutan bagian = urutan pertanyaan pengunjung: ini apa (hero: kartu asli + hasil tap)
// → bagaimana cara kerjanya → untuk siapa → kartu digital vs fisik → desain → bio link → setelah tap → perusahaan
// → pesan cetak → kenapa beralih → harga → keandalan → FAQ → mulai.
// Data awal (harga, FAQ, kartu hero, profil contoh) disisipkan server → halaman & render server (SSR) langsung
// lengkap; etalase berat (semua template, editor, katalog bio) diambil klien setelah halaman tampil.
import { computed, onMounted, ref } from 'vue'
import {
  Activity, ArrowRight, Bell, CalendarCheck, ChartColumn, Check, DatabaseBackup, Globe, IdCard, Lock, MessageCircle,
  Nfc, Palette, QrCode, ScanLine, ShieldCheck, Smartphone, Ticket, Truck, Users, Wallet, Webhook, X,
} from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import BioShowcase from '@/components/marketing/BioShowcase/index.vue'
import EditorShowcase from '@/components/marketing/EditorShowcase/index.vue'
import FaqList from '@/components/marketing/FaqList/index.vue'
import HeroDemo from '@/components/marketing/HeroDemo/index.vue'
import DashboardFrame from '@/components/marketing/DashboardFrame/index.vue'
import HowItWorks from '@/components/marketing/HowItWorks/index.vue'
import ProductGrid from '@/components/marketing/ProductGrid/index.vue'
import TickerStrip from '@/components/marketing/TickerStrip/index.vue'
import LiveStatus from '@/components/marketing/LiveStatus/index.vue'
import PricingTable from '@/components/marketing/PricingTable/index.vue'
import SectionHeading from '@/components/marketing/SectionHeading/index.vue'
import SiteFooter from '@/components/marketing/SiteFooter/index.vue'
import SiteHeader from '@/components/marketing/SiteHeader/index.vue'
import StickyActions from '@/components/marketing/StickyActions/index.vue'
import TemplateGallery from '@/components/marketing/TemplateGallery/index.vue'
import LanyardMockup from '@/components/studio/LanyardMockup/index.vue'
import { marketingApi } from '@/api/public'
import { config, takeInitial } from '@/config'
import { salesWhatsAppUrl } from '@/lib/salesWhatsApp'
import { track } from '@/lib/track'
import { vReveal } from '@/lib/reveal'

const initial = takeInitial('landing') ?? {}
const full = ref(null)
const show = computed(() => (full.value ? { ...initial, ...full.value } : initial))
const hero = computed(() => full.value?.templates?.[0] ?? initial.hero ?? null)
const signup = `${config.appUrl}/login`
const HERO_WORDS = ['Kartu', 'nama', 'yang', 'menyimpan', 'kontak', 'Anda', 'dengan']
onMounted(() => {
  marketingApi.showcase().then((r) => (full.value = r.data)).catch(() => {})
})

const rupiah = (n) => (n == null ? '—' : new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n))
const num = (n) => new Intl.NumberFormat('id-ID').format(n ?? 0)
const digital = computed(() => show.value.digital_card ?? { tap_quota: 1500, bio_pages: 1, topup_taps: 2000, topup_price: 70000 })
// Katalog kartu (card_products, CardProduct): ID badge dipisah dari kartu fisik NFC biasa
const isIdBadge = (c) => /ID Badge/i.test(c.name)
const physicalCards = computed(() => (show.value.card_products ?? []).filter((c) => !isIdBadge(c)))
const idBadge = computed(() => (show.value.card_products ?? []).find(isIdBadge) ?? null)
const cardFrom = computed(() => (physicalCards.value.length ? Math.min(...physicalCards.value.map((c) => c.price)) : null))
const pvc = computed(() => physicalCards.value.find((c) => c.chip === 'ntag215') ?? physicalCards.value[0] ?? null)
const secure = computed(() => physicalCards.value.find((c) => c.chip === 'ntag424') ?? null)
const personalPro = computed(() => (show.value.plans ?? []).find((p) => p.key === 'personal_pro') ?? null)
const companyPlans = computed(() => show.value.company_plans ?? [])
const trialDays = computed(() => companyPlans.value[0]?.trial_days ?? 14)
const companyFrom = computed(() => (companyPlans.value.length ? Math.min(...companyPlans.value.map((p) => p.price_semiannual)) : null))
const merch = computed(() => {
  const all = show.value.merch ?? []
  const ready = all.filter((m) => !m.coming_soon).map((m) => m.name)
  const soon = all.filter((m) => m.coming_soon).map((m) => m.name)
  return [ready.length ? `${ready.join(', ')} — tersedia` : '', soon.length ? `${soon.join(', ')} — segera hadir` : ''].filter(Boolean).join('; ') || 'Kaos, tote bag, mug — segera hadir'
})
const merchAllSoon = computed(() => (show.value.merch ?? []).every((m) => m.coming_soon))
// ID badge + lanyard (bagian perusahaan): tali 20 mm bila ada, pengait pertama dari katalog
const lanyard = computed(() => {
  const e = show.value.editor
  if (!e) return null
  const keys = Object.keys(e.lanyards ?? {})
  const tile = e.lanyards[keys.includes('20') ? '20' : keys[0]]
  const hw = Object.values(e.hardware ?? {})[0]?.key
  return tile ? { tile, card: e.paired_card.front, hardware: hw } : null
})
const wa = (place) => salesWhatsAppUrl(config.salesWhatsapp, place)
const cta = (location) => track('cta_click', { location })

// Sembilan produk untuk bagian "Produk". Setiap fakta bersumber dari katalog/config/seeder:
// - digital: config/tapcard.php digital_card.tap_quota (show.digital_card)
// - kartu fisik & ID badge: tabel card_products (migrasi 2026_11_15 & 2026_11_25) lewat show.card_products
// - lanyard: tabel lanyard_widths (migrasi 2026_11_08: 15/20/25 mm, harga single mulai show.lanyard_from)
// - event & check-in: PlanSeeder (event_mode di semua paket personal; events_checkin di paket perusahaan)
// - bio link: show.physical_bio_pages (BioBlocks::MAX_PAGES) dan digital_card.bio_pages
// - pengingat follow-up: PlanSeeder (reminders false di Personal Free, true di Personal Pro)
// - webhook: PlanSeeder (webhooks true hanya di paket Business)
// - merchandise: merch_products (migrasi 2026_11_23) status coming_soon → show.merch
const PRODUCTS = computed(() => [
  {
    key: 'digital', icon: Smartphone, name: 'Kartu digital',
    what: 'Profil di ponsel, dibagikan lewat QR atau tautan pendek. Tanpa cetak.',
    who: 'Siapa saja yang ingin mulai hari ini.',
    fact: `Gratis, ${num(digital.value.tap_quota)} tap`,
  },
  {
    key: 'pvc', icon: Nfc, name: 'Kartu fisik NFC',
    what: `Kartu PVC 0,76 mm, cetak dua sisi, chip NFC + QR${secure.value ? `. Varian Secure (chip NTAG 424 DNA, anti-salin) ${rupiah(secure.value.price)}` : ''}.`,
    who: 'Orang yang sering bertemu klien atau mitra secara langsung.',
    fact: pvc.value ? `${rupiah(pvc.value.price)} per kartu` : 'Harga tampil saat memesan',
  },
  {
    key: 'badge', icon: IdCard, name: 'ID badge',
    what: 'Kartu ID potret dengan lubang lanyard, cetak dua sisi, chip NFC.',
    who: 'Panitia acara, staf, dan tim yang memakai tali.',
    fact: idBadge.value ? `${rupiah(idBadge.value.price)} per kartu` : 'Harga tampil saat memesan',
  },
  {
    key: 'lanyard', icon: Ticket, name: 'Lanyard',
    what: 'Tali leher serasi dengan ID badge, pilihan lebar 15, 20, atau 25 mm.',
    who: 'Acara, expo, dan tim yang memakai ID badge.',
    fact: `Mulai ${rupiah(show.value.lanyard_from)} per pcs`,
  },
  {
    key: 'event', icon: CalendarCheck, name: 'Mode Event & check-in',
    what: 'Tandai koneksi per pameran. Panitia mencatat peserta lewat tap kartu atau scan QR.',
    who: 'Panitia acara, expo, dan komunitas.',
    fact: 'Mode Event ada di semua paket personal; check-in peserta di paket perusahaan',
  },
  {
    key: 'bio', icon: QrCode, name: 'Bio link & QR',
    what: 'Halaman tautan berisi kontak, tombol, video, dan formulir. Terbuka saat kartu di-tap atau QR dipindai.',
    who: 'Pemilik usaha, kreator, dan profesional yang butuh beberapa tautan.',
    fact: `${digital.value.bio_pages} bio link di kartu digital; sampai ${show.value.physical_bio_pages ?? 3} per kartu fisik`,
  },
  {
    key: 'lead', icon: Users, name: 'Lead & follow-up',
    what: 'Setiap koneksi tercatat sebagai lead, dengan tahap dan catatan.',
    who: 'Sales dan tim yang perlu menindaklanjuti pertemuan.',
    fact: personalPro.value ? `Pengingat follow-up mulai Personal Pro, ${rupiah(personalPro.value.price_monthly)} per bulan` : 'Tahap & catatan lead di semua paket',
  },
  {
    key: 'webhook', icon: Webhook, name: 'Webhook untuk perusahaan',
    what: 'Lead, tap, dan check-in dikirim otomatis ke CRM atau spreadsheet Anda.',
    who: 'Perusahaan yang sudah memakai sistem sendiri.',
    fact: 'Tersedia di paket Business',
  },
  {
    key: 'merch', icon: Palette, name: 'Desain & merchandise',
    what: 'Editor kartu dan lanyard di browser. Merchandise: kaos, jaket, tote bag, mug, topi.',
    who: 'Tim dan komunitas yang ingin tampilan seragam.',
    fact: `Kartu & lanyard bisa didesain sekarang. Merchandise ${merchAllSoon.value ? 'segera hadir' : 'sebagian sudah tersedia'}`,
  },
])
const AFTER = [
  { icon: ChartColumn, title: 'Analitik tap yang jujur', text: 'Kota, NFC atau QR, perangkat, dan tautan yang diklik. Kunjungan berulang & bot tidak menggelembungkan angka.' },
  { icon: Bell, title: 'Tahu saat profil dibuka', text: 'Notifikasi seketika — tap beruntun dari satu kartu digabung, jadi Anda tidak dibanjiri.' },
  { icon: CalendarCheck, title: 'Mode Event & check-in', text: 'Tandai koneksi per pameran; panitia check-in peserta dengan tap kartu atau scan QR.' },
  { icon: QrCode, title: 'QR aman sekali pakai', text: 'QR dinamis yang kedaluwarsa — tangkapan layar QR Anda tidak bisa dipakai ulang.' },
  { icon: ShieldCheck, title: 'Kartu hilang? Aman', text: 'Tandai hilang: tap berikutnya hanya melihat halaman netral, Anda diberi tahu kotanya.' },
]
const LEADS = [
  { name: 'Dimas Pratama', meta: 'Expo UMKM · lewat NFC', stage: 'Baru' },
  { name: 'Sari Wulandari', meta: 'Formulir bio · Bandung', stage: 'Dihubungi' },
  { name: 'PT Arta Niaga', meta: 'Tukar Kontak · QR', stage: 'Penawaran' },
]
const COMPANY = [
  { icon: Globe, title: 'Subdomain & domain sendiri', text: `perusahaan.${config.baseDomain} untuk semua paket, atau kartu.perusahaananda.com di paket Business.` },
  { icon: IdCard, title: 'Kartu & ID badge seragam', text: 'Satu desain dengan logo & warna organisasi, dipesan sekaligus bersama lanyard serasi.' },
  { icon: Users, title: 'Kartu ditugaskan ke anggota', text: 'Tambah anggota, bagikan kartu dari stok, tarik kembali saat anggota keluar — riwayat tetap milik organisasi.' },
  { icon: ScanLine, title: 'Check-in acara & lead tim', text: 'Lead dari setiap anggota & acara terkumpul di satu workspace, bisa ditugaskan ke penanggung jawab.' },
  { icon: Webhook, title: 'Webhook ke sistem Anda', text: 'Lead, tap, dan check-in dikirim ke CRM atau spreadsheet Anda secara otomatis (paket Business).' },
]
const COMPARE = [
  ['Data bisa diperbarui setelah dicetak', false, true],
  ['Kontak tersimpan otomatis ke ponsel', false, true],
  ['Tahu siapa & kapan kartu dibuka', false, true],
  ['Lawan bicara bisa berbagi kontak balik', false, true],
  ['Aman saat kartu hilang', false, true],
]
const RELIABLE = [
  { icon: Activity, title: 'Dipantau setiap menit', text: 'Aplikasi, tap kartu, dan situs dicek terus-menerus; gangguan diumumkan terbuka di halaman status.' },
  { icon: DatabaseBackup, title: 'Backup harian di luar server', text: 'Database dicadangkan setiap malam ke penyimpanan terpisah dan disimpan 30 hari.' },
  { icon: Lock, title: 'Privasi sejak awal', text: 'IP pengunjung hanya disimpan sebagai hash; data bisa diekspor atau dihapus kapan saja.' },
]
</script>

<template>
  <div class="min-h-dvh bg-bg pb-20 text-fg sm:pb-0">
    <SiteHeader />

    <main>
      <!-- Hero: produk nyata + hasil tap + satu aksi utama dalam layar pertama (ponsel: judul → visual → aksi) -->
      <section id="hero" class="mx-auto grid max-w-[1200px] grid-cols-[minmax(0,1fr)] justify-items-center gap-6 px-4 pt-12 pb-10 text-center sm:px-6 lg:pt-20">
        <span class="hero-in inline-flex max-w-full items-center gap-2 rounded-full border border-line bg-surface p-1 pr-4 text-sm text-muted hd-0">
          <span class="live-dot ml-2" aria-hidden="true"></span>
          <span class="rounded-full bg-accent px-3 py-0.5 text-xs font-semibold text-accent-ink">Populer</span>
          <span class="truncate">Kartu nama NFC & QR · bio link · lead</span>
        </span>
        <h1 class="max-w-[860px] font-display text-[36px] leading-[1.05] font-bold tracking-[-.02em] sm:text-[52px] lg:text-[64px]">
          <template v-for="(w, i) in HERO_WORDS" :key="w"><span class="hero-word" :class="`hw-${i}`">{{ w }}</span>{{ ' ' }}</template>
          <span class="hero-word text-accent-text hw-7">satu tap</span><span class="hero-word hw-8">.</span>
        </h1>
        <p class="hero-in max-w-[640px] text-base text-muted sm:text-lg hd-420">
          Kartu ini berisi profil Anda. Orang lain cukup menempelkan kartu ke HP mereka, atau memindai QR-nya,
          lalu profil Anda langsung terbuka dan mereka bisa menyimpan kontak Anda. Tanpa install aplikasi.
          Mulai gratis dengan kartu digital, pesan kartu fisik kapan saja.
        </p>
        <form :action="signup" method="get" class="hero-in hd-600 flex w-full max-w-[520px] items-center gap-2 rounded-full border border-line-strong bg-surface p-1.5 pl-5 text-left focus-within:border-accent">
          <label for="hero-email" class="sr-only">Email kerja</label>
          <input id="hero-email" type="email" name="email" autocomplete="email" placeholder="Email kerja Anda" class="min-w-0 flex-1 bg-transparent text-fg outline-none placeholder:text-muted" />
          <AppButton type="submit" class="cta-lift !rounded-full whitespace-nowrap" data-event="cta_click" data-location="hero" @click="cta('hero')">Mulai gratis <ArrowRight class="size-5" /></AppButton>
        </form>
        <div class="hero-in flex flex-wrap items-center justify-center gap-x-5 gap-y-2 text-sm text-muted hd-700">
          <span class="flex items-center gap-2"><Check class="size-4 text-ok" aria-hidden="true" /> Gratis {{ num(digital.tap_quota) }} tap, tanpa kartu kredit</span>
          <span class="flex items-center gap-2"><Nfc class="size-4 text-accent-text" aria-hidden="true" /> NFC + QR di setiap kartu</span>
          <span class="flex items-center gap-2"><Smartphone class="size-4 text-accent-text" aria-hidden="true" /> iPhone & Android</span>
          <AppButton variant="ghost" href="#cara-kerja" class="max-sm:hidden">Lihat cara kerja</AppButton>
        </div>
        <!-- Ilustrasi kartu + ponsel dengan data contoh (bukan data pengguna nyata) -->
        <div class="hero-in w-full max-w-[560px] pt-4 hd-850">
          <HeroDemo v-if="hero && show.sample" :card="hero.preview.landscape.front" :vars="show.variables" :qr="show.qr" :profile="show.sample" />
          <AppSkeleton v-else class="!aspect-[16/10] !h-auto w-full" />
        </div>
        <!-- Bingkai dashboard besar di bawah visual hero (layar pertama tetap: judul, aksi, kartu + ponsel) -->
        <div v-reveal class="w-full max-w-[1200px]">
          <DashboardFrame />
        </div>
      </section>

      <TickerStrip />

      <!-- Cara kerja: animasi dari dekat ke tap sampai kontak tersimpan (bingkai akhir langsung tampil tanpa JS) -->
      <section id="cara-kerja" v-reveal class="scroll-mt-20 border-y border-line bg-surface">
        <div class="mx-auto grid max-w-[1200px] gap-10 px-4 py-16 sm:px-6 lg:py-20">
          <SectionHeading eyebrow="Cara kerja" title="Dari tap ke kontak tersimpan, langkah demi langkah" text="Contoh ilustrasi dengan data fiktif. Penerima tidak perlu install aplikasi." />
          <HowItWorks v-if="hero && show.sample" :card="hero.preview.landscape.front" :vars="show.variables" :qr="show.qr" :profile="show.sample" />
          <AppSkeleton v-else class="!h-[420px] w-full" />
        </div>
      </section>

      <!-- Untuk siapa: dua jalur -->
      <section id="untuk-siapa" v-reveal class="mx-auto grid max-w-[1200px] scroll-mt-20 gap-10 px-4 py-16 sm:px-6 lg:py-20">
        <SectionHeading eyebrow="Untuk siapa" title="Satu kartu untuk Anda, atau seragam untuk seluruh tim" />
        <div class="grid gap-px overflow-hidden rounded-lg border border-line bg-line md:grid-cols-2">
          <article class="lift-card grid content-start gap-4 bg-bg p-6 sm:p-8">
            <span class="label">Perorangan</span>
            <h3 class="font-display text-2xl font-bold">Sales, konsultan, freelancer, pemilik usaha</h3>
            <ul class="grid gap-2 text-sm">
              <li class="flex gap-2"><Check class="size-5 flex-none text-ok" aria-hidden="true" /> Kartu digital gratis hari ini, kartu fisik NFC saat siap</li>
              <li class="flex gap-2"><Check class="size-5 flex-none text-ok" aria-hidden="true" /> Bio link berbeda per kartu — kerja, pameran, personal</li>
              <li class="flex gap-2"><Check class="size-5 flex-none text-ok" aria-hidden="true" /> Setiap orang yang ditemui tercatat untuk di-follow up</li>
            </ul>
            <AppButton class="justify-self-start" :href="signup" data-event="cta_click" data-location="personal" @click="cta('personal')">Buat kartu gratis <ArrowRight class="size-5" /></AppButton>
          </article>
          <article class="lift-card grid content-start gap-4 bg-surface p-6 sm:p-8">
            <span class="label">Perusahaan, komunitas & organisasi</span>
            <h3 class="font-display text-2xl font-bold">Tim sales, agensi, HR, panitia acara, organisasi</h3>
            <ul class="grid gap-2 text-sm">
              <li class="flex gap-2"><Check class="size-5 flex-none text-ok" aria-hidden="true" /> Workspace dengan subdomain sendiri & logo organisasi</li>
              <li class="flex gap-2"><Check class="size-5 flex-none text-ok" aria-hidden="true" /> Kartu & ID badge seragam, ditugaskan ke setiap anggota</li>
              <li class="flex gap-2"><Check class="size-5 flex-none text-ok" aria-hidden="true" /> Harga tetap per 6 bulan{{ companyFrom ? ` mulai ${rupiah(companyFrom)}` : '' }} — anggota tidak dibatasi</li>
            </ul>
            <AppButton class="justify-self-start" variant="secondary" href="#perusahaan" data-event="cta_click" data-location="audience_company" @click="cta('audience_company')">Lihat fitur organisasi</AppButton>
          </article>
        </div>
      </section>

      <!-- Produk: setiap produk = apa, untuk siapa, dan satu fakta dari katalog -->
      <section id="produk" v-reveal class="mx-auto grid max-w-[1200px] scroll-mt-20 gap-10 px-4 py-16 sm:px-6 lg:py-20">
        <SectionHeading eyebrow="Produk" title="Semua yang bisa Anda pesan atau pakai" text="Satu ringkasan per produk: apa itu, untuk siapa, dan satu fakta dari katalog kami." />
        <ProductGrid :items="PRODUCTS" />
      </section>

      <!-- Kartu digital vs fisik -->
      <section id="kartu" v-reveal class="scroll-mt-20 border-t border-line bg-surface">
        <div class="mx-auto grid max-w-[1200px] gap-10 px-4 py-16 sm:px-6 lg:py-20">
          <SectionHeading eyebrow="Dua cara berbagi" title="Mulai digital hari ini, tambah kartu fisik saat siap" text="Keduanya membuka bio link yang sama, menyimpan kontak dengan satu ketukan, dan mencatat setiap koneksi." />
          <div class="grid gap-4 md:grid-cols-2">
            <article v-reveal class="st-0 lift-card grid content-start gap-4 rounded-lg border border-line bg-bg p-6">
              <span class="flex items-center gap-3"><Smartphone class="size-7 text-accent-text" aria-hidden="true" /><h3 class="font-display text-2xl font-bold">Kartu digital</h3></span>
              <p class="font-display text-[32px] font-bold">Gratis <span class="text-base font-normal text-muted">untuk setiap akun</span></p>
              <ul class="grid gap-2 text-sm">
                <li class="flex gap-2"><Check class="size-5 flex-none text-ok" aria-hidden="true" /> QR di layar ponsel & tautan pendek @nama</li>
                <li class="flex gap-2"><Check class="size-5 flex-none text-ok" aria-hidden="true" /> {{ num(digital.tap_quota) }} tap — tidak di-reset bulanan</li>
                <li class="flex gap-2"><Check class="size-5 flex-none text-ok" aria-hidden="true" /> {{ digital.bio_pages }} bio link dengan semua blok & tema</li>
                <li class="flex gap-2"><Check class="size-5 flex-none text-ok" aria-hidden="true" /> Tambah {{ num(digital.topup_taps) }} tap seharga {{ rupiah(digital.topup_price) }} bila habis</li>
              </ul>
            </article>
            <article v-reveal class="st-1 lift-card grid content-start gap-4 rounded-lg border border-accent bg-bg p-6">
              <span class="flex items-center gap-3"><Nfc class="size-7 text-accent-text" aria-hidden="true" /><h3 class="font-display text-2xl font-bold">Kartu fisik NFC</h3></span>
              <p class="font-display text-[32px] font-bold">{{ cardFrom ? `Mulai ${rupiah(cardFrom)}` : '—' }} <span class="text-base font-normal text-muted">per kartu</span></p>
              <dl class="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-2 text-sm">
                <dt class="text-muted">Ukuran</dt><dd>85,6 × 54 mm (seukuran kartu ATM), cetak penuh dua sisi</dd>
                <dt class="text-muted">Teknologi</dt><dd>Chip NFC + QR di setiap kartu — tanpa baterai</dd>
                <dt class="text-muted">Tap</dt><dd>Tanpa batas, sampai {{ show.physical_bio_pages ?? 3 }} bio link per kartu</dd>
                <dt class="text-muted">Pengiriman</dt><dd>Reguler 3–5 hari kerja, ekspres 1–2 hari kerja, atau ambil sendiri</dd>
              </dl>
            </article>
          </div>
        </div>
      </section>

      <!-- Desain: template -->
      <section id="template" v-reveal class="scroll-mt-20 border-t border-line bg-surface-2">
        <div class="mx-auto grid max-w-[1200px] gap-10 px-4 py-16 sm:px-6 lg:py-20">
          <SectionHeading
            :eyebrow="show.template_count ? `${show.template_count} template siap cetak` : 'Template siap cetak'"
            title="Pilih desain, atau buat sendiri di browser"
            text="Setiap template lolos pemeriksaan cetak — safe zone 4 mm, bleed 3 mm, QR minimal 15 mm, aman untuk lubang lanyard. Logo organisasi otomatis terpasang di desain anggota."
          />
          <TemplateGallery v-if="full" :templates="show.templates" :vars="show.variables" :qr="show.qr" />
          <div v-else class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4"><AppSkeleton v-for="i in 8" :key="i" class="!h-44" /></div>
        </div>
      </section>

      <!-- Desain: editor -->
      <section id="editor" v-reveal class="mx-auto grid max-w-[1200px] scroll-mt-20 gap-8 px-4 py-16 sm:px-6 lg:py-20">
        <SectionHeading eyebrow="Design Studio" title="Editor kartu & lanyard — cobalah di sini" text="Editor yang sama dengan di aplikasi: 28 font, teks melengkung, potong gambar ke bentuk, penggaris mm, dan pola lanyard berulang." />
        <EditorShowcase v-if="full?.editor" :templates="show.templates" :vars="show.variables" :qr="show.qr" :editor="show.editor" />
        <AppSkeleton v-else class="!h-[560px]" />
      </section>

      <!-- Bio link -->
      <section id="bio-link" v-reveal class="scroll-mt-20 border-t border-line bg-surface-2">
        <div class="mx-auto grid max-w-[1200px] gap-10 px-4 py-16 sm:px-6 lg:py-20">
          <SectionHeading
            eyebrow="Bio link per kartu"
            title="Halaman yang terbuka saat kartu di-tap — sepenuhnya milik Anda"
            text="Simpan ke Kontak HP, kontak & tautan, tombol, teks, video, dan formulir. Atur tema dan warna, lihat hasilnya langsung."
          />
          <BioShowcase v-if="full" :catalog="show.bio" :sample="show.sample" />
          <AppSkeleton v-else class="!h-[640px]" />
        </div>
      </section>

      <!-- Setelah tap: bento -->
      <section id="fitur" v-reveal class="mx-auto grid max-w-[1200px] scroll-mt-20 gap-10 px-4 py-16 sm:px-6 lg:py-20">
        <SectionHeading eyebrow="Setelah tap" title="Setiap pertemuan jadi lead yang bisa di-follow up" />
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <article class="lift-card grid content-start gap-4 rounded-lg border border-accent bg-surface p-6 sm:col-span-2 lg:col-span-1 lg:row-span-2">
            <Users class="size-6 text-accent-text" aria-hidden="true" />
            <h3 class="font-display text-2xl font-bold">Tukar Kontak & pipeline</h3>
            <p class="text-sm text-muted">Lawan bicara membagikan kontaknya balik dengan persetujuan tercatat. Kontak ganda digabung otomatis; tahap, catatan, pengingat follow-up, ekspor & impor CSV.</p>
            <ul class="grid divide-y divide-line rounded-md border border-line bg-bg text-sm" aria-label="Contoh daftar lead">
              <li v-for="l in LEADS" :key="l.name" class="flex items-center gap-3 px-4 py-3">
                <span class="grid min-w-0 flex-1"><strong class="truncate font-semibold">{{ l.name }}</strong><span class="truncate text-xs text-muted">{{ l.meta }}</span></span>
                <span class="rounded-full border border-line-strong px-2 py-0.5 text-xs whitespace-nowrap">{{ l.stage }}</span>
              </li>
            </ul>
            <p class="text-xs text-muted">Contoh tampilan dengan nama fiktif.</p>
          </article>
          <article v-for="(f, i) in AFTER" :key="f.title" v-reveal :class="[`st-${i}`, 'lift-card grid content-start gap-2 rounded-lg border border-line p-6', i === AFTER.length - 1 ? 'sm:col-span-2 lg:col-span-3' : '']">
            <component :is="f.icon" class="size-6 text-accent-text" aria-hidden="true" />
            <h3 class="mt-1 text-lg font-semibold">{{ f.title }}</h3>
            <p class="text-sm text-muted">{{ f.text }}</p>
          </article>
        </div>
      </section>

      <!-- Perusahaan -->
      <section id="perusahaan" v-reveal class="scroll-mt-20 border-t border-line bg-surface">
        <div class="mx-auto grid max-w-[1200px] items-start gap-12 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:py-20">
          <div class="grid content-start gap-8">
            <SectionHeading
              eyebrow="Perusahaan, komunitas & organisasi"
              title="Satu tampilan untuk seluruh anggota — semua lead masuk ke satu tempat"
              :text="`Workspace organisasi dibayar per 6 bulan dengan harga tetap, jumlah anggota tidak dibatasi, uji coba gratis ${trialDays} hari.`"
            />
            <ul class="grid gap-5">
              <li v-for="(f, i) in COMPANY" :key="f.title" v-reveal :class="`st-${i}`" class="flex gap-4">
                <component :is="f.icon" class="mt-0.5 size-6 flex-none text-accent-text" aria-hidden="true" />
                <span class="grid gap-1"><strong>{{ f.title }}</strong><span class="text-sm text-muted">{{ f.text }}</span></span>
              </li>
            </ul>
            <div class="flex flex-wrap items-center gap-3">
              <AppButton :href="signup" data-event="cta_click" data-location="company" @click="cta('company')">Coba gratis {{ trialDays }} hari <ArrowRight class="size-5" /></AppButton>
              <AppButton
                v-if="wa('company')" variant="secondary" :href="wa('company')" target="_blank" rel="noopener"
                data-event="whatsapp_click" data-location="company" @click="track('whatsapp_click', { location: 'company' })"
              ><MessageCircle class="size-5 text-ok" /> Hubungi Sales</AppButton>
            </div>
          </div>
          <figure class="grid gap-3">
            <div class="grid min-h-[360px] place-items-center overflow-hidden rounded-lg border border-line bg-bg p-4">
              <LanyardMockup v-if="lanyard" :lanyard="lanyard.tile.front" :back="lanyard.tile.back" :card="lanyard.card" :vars="show.variables" :qr="show.qr" :hardware="lanyard.hardware" :scale="1.5" />
              <AppSkeleton v-else class="!h-[320px] w-full" />
            </div>
            <figcaption class="text-sm text-muted">ID badge potret dengan lanyard serasi — dari Design Studio, dicetak sekaligus untuk seluruh anggota.</figcaption>
          </figure>
        </div>
      </section>

      <!-- Pesan kartu & lanyard -->
      <section id="pesan" v-reveal class="mx-auto grid max-w-[1200px] scroll-mt-20 gap-10 px-4 py-16 sm:px-6 lg:py-20">
        <SectionHeading eyebrow="Pesan langsung dari aplikasi" title="Kartu, ID badge, dan lanyard — dicetak sesuai desain Anda" />
        <div class="grid gap-4 md:grid-cols-3">
          <article v-for="(c, i) in show.card_products ?? []" :key="c.name" v-reveal :class="`st-${i}`" class="lift-card grid content-start gap-2 rounded-lg border border-line bg-surface p-5">
            <h3 class="text-lg font-semibold">{{ c.name }}</h3>
            <p class="font-display text-2xl font-bold">{{ rupiah(c.price) }} <span class="text-sm font-normal text-muted">/ kartu</span></p>
            <p class="text-sm text-muted">{{ c.description }}</p>
          </article>
        </div>
        <ul class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
          <li class="flex gap-3"><Palette class="size-5 flex-none text-accent-text" aria-hidden="true" /><span><strong class="block">Lanyard serasi</strong><span class="text-muted">15/20/25 mm, mulai {{ rupiah(show.lanyard_from) }} per pcs</span></span></li>
          <li class="flex gap-3"><Wallet class="size-5 flex-none text-accent-text" aria-hidden="true" /><span><strong class="block">Bayar QRIS</strong><span class="text-muted">Semua e-wallet & mobile banking, kuitansi PDF</span></span></li>
          <li class="flex gap-3"><Truck class="size-5 flex-none text-accent-text" aria-hidden="true" /><span><strong class="block">Kirim atau ambil</strong><span class="text-muted">Reguler 3–5 hari, ekspres 1–2 hari kerja</span></span></li>
          <li class="flex gap-3"><IdCard class="size-5 flex-none text-accent-text" aria-hidden="true" /><span><strong class="block">Merchandise</strong><span class="text-muted">{{ merch }}</span></span></li>
        </ul>
      </section>

      <!-- Perbandingan -->
      <section v-reveal class="border-t border-line bg-surface-2">
        <div class="mx-auto grid max-w-[900px] gap-8 px-4 py-16 sm:px-6 lg:py-20">
          <SectionHeading center eyebrow="Kenapa beralih" title="Kartu kertas berhenti bekerja setelah diberikan" />
          <div class="overflow-x-auto rounded-lg border border-line bg-bg">
            <table class="w-full text-left text-sm">
              <thead class="bg-surface">
                <tr><th class="px-4 py-3 font-medium text-muted"><span class="sr-only">Kemampuan</span></th><th class="px-4 py-3 text-center font-medium text-muted">Kartu kertas</th><th class="px-4 py-3 text-center font-semibold text-accent-text">TapCard</th></tr>
              </thead>
              <tbody>
                <tr v-for="[label, paper, tap] in COMPARE" :key="label" class="border-t border-line">
                  <td class="px-4 py-3">{{ label }}</td>
                  <td class="px-4 py-3 text-center"><component :is="paper ? Check : X" :class="['mx-auto size-5', paper ? 'text-ok' : 'text-muted']" :aria-label="paper ? 'Ya' : 'Tidak'" /></td>
                  <td class="px-4 py-3 text-center"><component :is="tap ? Check : X" :class="['mx-auto size-5', tap ? 'text-ok' : 'text-muted']" :aria-label="tap ? 'Ya' : 'Tidak'" /></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- Harga -->
      <section id="harga" v-reveal class="mx-auto grid max-w-[1200px] scroll-mt-20 gap-10 px-4 py-16 sm:px-6 lg:py-20">
        <SectionHeading eyebrow="Harga" title="Mulai gratis, upgrade saat jaringan Anda tumbuh" text="Kartu digital gratis termasuk di semua paket; kartu fisik & lanyard dipesan terpisah sesuai desain Anda." />
        <PricingTable
          v-if="show.plans" :plans="show.plans" :company-plans="companyPlans" :physical-bio-pages="show.physical_bio_pages"
          :template-count="show.template_count" :sales-whatsapp="config.salesWhatsapp"
        />
        <div class="flex flex-wrap items-center gap-4 rounded-md border border-line bg-surface px-5 py-4">
          <Webhook class="size-6 flex-none text-accent-text" aria-hidden="true" />
          <div class="grid min-w-0 flex-1 gap-0.5">
            <span class="font-semibold">Integrasi CRM & pesan <span class="ml-1 rounded-full bg-surface-2 px-2 py-0.5 text-xs font-medium text-info">Segera hadir</span></span>
            <span class="text-sm text-muted">WhatsApp, Telegram, HubSpot, Google Sheets, Zoho, dan Salesforce. Sambil menunggu, paket Business bisa memakai webhook.</span>
          </div>
        </div>
      </section>

      <!-- Keandalan -->
      <section id="keandalan" v-reveal class="scroll-mt-20 border-t border-line bg-surface">
        <div class="mx-auto grid max-w-[1200px] gap-10 px-4 py-16 sm:px-6 lg:grid-cols-[1fr_1.1fr] lg:items-start lg:py-20">
          <div class="grid content-start gap-8">
            <SectionHeading eyebrow="Keandalan" title="Setiap tap harus berhasil, kapan pun" text="Kami memantau layanan terus-menerus dan mengumumkan setiap gangguan secara terbuka." />
            <ul class="grid gap-6">
              <li v-for="(r, i) in RELIABLE" :key="r.title" v-reveal :class="`st-${i}`" class="flex gap-4">
                <component :is="r.icon" class="mt-0.5 size-6 flex-none text-accent-text" aria-hidden="true" />
                <span class="grid gap-1"><strong class="text-lg">{{ r.title }}</strong><span class="text-sm text-muted">{{ r.text }}</span></span>
              </li>
            </ul>
          </div>
          <LiveStatus />
        </div>
      </section>

      <!-- FAQ -->
      <section id="faq" v-reveal class="mx-auto grid max-w-[1200px] scroll-mt-20 gap-8 px-4 py-16 sm:px-6 lg:grid-cols-[1fr_1.6fr] lg:py-20">
        <SectionHeading eyebrow="FAQ" title="Pertanyaan yang sering diajukan" text="Belum terjawab? Tanyakan langsung ke tim kami." />
        <FaqList :items="show.faq ?? []" />
      </section>

      <!-- CTA penutup -->
      <section id="penutup" v-reveal class="bg-accent text-accent-ink">
        <div class="mx-auto grid max-w-[1200px] justify-items-start gap-6 px-4 py-16 sm:px-6 lg:py-20">
          <h2 class="max-w-[760px] font-display text-[32px] leading-[1.05] font-bold tracking-[-.015em] sm:text-[48px]">Kartu digital Anda bisa aktif dalam semenit — gratis.</h2>
          <p class="max-w-[560px] text-lg">Bagikan lewat QR atau tautan hari ini. Pesan kartu fisik NFC kapan pun Anda siap.</p>
          <div class="flex flex-wrap items-center gap-3">
            <AppButton size="lg" :href="signup" class="cta-lift !bg-onyx-950 !text-bone-50 focus-visible:!outline-onyx-950" data-event="cta_click" data-location="closing" @click="cta('closing')">Buat kartu gratis <ArrowRight class="size-5" /></AppButton>
            <AppButton
              v-if="wa('closing')" size="lg" variant="secondary" :href="wa('closing')" target="_blank" rel="noopener"
              class="!border-onyx-950 !text-onyx-950 hover:!bg-champagne-300 focus-visible:!outline-onyx-950" data-event="whatsapp_click" data-location="closing" @click="track('whatsapp_click', { location: 'closing' })"
            ><MessageCircle class="size-5" /> Hubungi Sales</AppButton>
          </div>
        </div>
      </section>
    </main>

    <SiteFooter />
    <StickyActions :signup="signup" />
  </div>
</template>
