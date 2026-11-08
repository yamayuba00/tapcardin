<script setup>
// Landing page (domain utama): menjual nilai produk — kartu digital gratis + kartu fisik NFC, bio link per kartu,
// tindak lanjut koneksi, paket tim/perusahaan, pemesanan kartu & lanyard, dan keandalan layanan (status langsung).
// Angka (harga, kuota, produk) dari GET /api/showcase — sama dengan yang berlaku di aplikasi & bisa diubah dari panel.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  Activity, ArrowRight, Bell, Blocks, CalendarCheck, ChartColumn, Check, ClipboardList, DatabaseBackup, Download, Globe, IdCard, Layers, Lock,
  MapPin, Nfc, Palette, Pencil, QrCode, ShieldCheck, Smartphone, Truck, UserPlus, Users, Wallet, X, Zap,
} from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import BioShowcase from '@/components/marketing/BioShowcase/index.vue'
import EditorShowcase from '@/components/marketing/EditorShowcase/index.vue'
import FaqList from '@/components/marketing/FaqList/index.vue'
import LiveStatus from '@/components/marketing/LiveStatus/index.vue'
import PricingTable from '@/components/marketing/PricingTable/index.vue'
import SectionHeading from '@/components/marketing/SectionHeading/index.vue'
import SiteFooter from '@/components/marketing/SiteFooter/index.vue'
import SiteHeader from '@/components/marketing/SiteHeader/index.vue'
import TemplateGallery from '@/components/marketing/TemplateGallery/index.vue'
import FlipCard from '@/components/studio/FlipCard/index.vue'
import { marketingApi } from '@/api/public'
import { config } from '@/config'
import { useAsync } from '@/composables/useAsync'

const { data: show } = useAsync(() => marketingApi.showcase())
const signup = `${config.appUrl}/login`
// format sama dengan tabel harga (Rp 49.000)
const rupiah = (n) => (n == null ? '—' : new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n))
const num = (n) => new Intl.NumberFormat('id-ID').format(n)

// Kartu hero membalik sendiri, kecuali pengguna memilih gerak dikurangi.
const heroFlipped = ref(false)
let timer = null
onMounted(() => {
  if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) timer = setInterval(() => (heroFlipped.value = !heroFlipped.value), 3800)
})
onBeforeUnmount(() => clearInterval(timer))

const digital = computed(() => show.value?.digital_card ?? { tap_quota: 1500, bio_pages: 1, topup_taps: 2000, topup_price: 70000 })
// merchandise: tersedia disebut apa adanya, sisanya "segera hadir" (status diatur dari panel)
const merch = computed(() => {
  const all = show.value?.merch ?? []
  const ready = all.filter((m) => !m.coming_soon).map((m) => m.name)
  const soon = all.filter((m) => m.coming_soon).map((m) => m.name)
  return [ready.length ? `${ready.join(', ')} — tersedia` : '', soon.length ? `${soon.join(', ')} — segera hadir` : ''].filter(Boolean).join('; ') || 'Kaos, tote bag, mug — segera hadir'
})
const cardFrom = computed(() => (show.value?.card_products?.length ? Math.min(...show.value.card_products.map((c) => c.price)) : null))

const VALUES = [
  { icon: Download, title: '2 detik, bukan 2 menit', text: 'Satu tombol Simpan ke Kontak HP: kontak di ponsel pengunjung langsung terisi nama, nomor, email, dan foto. Tidak ada salah ketik nomor lagi.' },
  { icon: Pencil, title: 'Cetak sekali, perbarui selamanya', text: 'Ganti jabatan, nomor, atau tautan kapan saja. Alamat di chip & QR permanen, jadi kartu tidak perlu dicetak ulang.' },
  { icon: UserPlus, title: 'Setiap tap jadi peluang', text: 'Lawan bicara bisa membagikan kontaknya balik lewat Tukar Kontak atau formulir di bio. Semua masuk pipeline untuk di-follow up.' },
]
const STEPS = [
  { n: '01', title: 'Daftar & langsung pakai', text: 'Masuk dengan Google — kartu digital Anda aktif seketika: QR di layar dan tautan tap…/@nama untuk WhatsApp.' },
  { n: '02', title: 'Pesan kartu fisik', text: 'Pilih template atau desain sendiri di Design Studio, lengkap dengan lanyard serasi. Bayar QRIS, kami cetak & kirim.' },
  { n: '03', title: 'Tempel & terhubung', text: 'Orang lain cukup menempelkan ponselnya atau scan QR. Tanpa aplikasi, di iPhone maupun Android.' },
]
const FEATURES = [
  { icon: Layers, title: 'Bio link per kartu', text: 'Kartu kantor dan kartu komunitas tampil berbeda. Susun blok: tombol, teks, video YouTube/TikTok/Spotify, dan formulir.' },
  { icon: Users, title: 'Tukar Kontak & formulir', text: 'Pengunjung berbagi kontak dengan persetujuan tercatat. Kontak ganda otomatis digabung berdasarkan nomor & email.' },
  { icon: Zap, title: 'Pipeline & pengingat', text: 'Tahapan, catatan, tag, nilai deal, dan pengingat follow-up otomatis. Dasbor lead yang bisa diatur sendiri, plus ekspor & impor CSV.' },
  { icon: ChartColumn, title: 'Analitik tap yang jujur', text: 'Dari kota mana, lewat NFC atau QR, perangkat apa, dan tautan mana yang diklik. Kunjungan berulang & bot tidak menggelembungkan angka.' },
  { icon: Bell, title: 'Notifikasi realtime & email', text: 'Kabar seketika saat bio dibuka atau ada koneksi baru. Pilih sendiri mana yang juga dikirim ke email.' },
  { icon: CalendarCheck, title: 'Mode Event', text: 'Tandai koneksi per pameran atau seminar, check-in peserta, dan lihat lead per event — tim pulang membawa daftar yang rapi.' },
  { icon: QrCode, title: 'QR aman sekali pakai', text: 'QR dinamis yang berganti dan kedaluwarsa — tangkapan layar QR Anda tidak bisa dipakai ulang orang lain.' },
  { icon: ShieldCheck, title: 'Kartu hilang? Aman', text: 'Tandai hilang: tap berikutnya hanya melihat halaman netral, Anda diberi tahu kotanya, dan bisa minta kartu pengganti dengan bio yang sama.' },
]
const COMPANY = [
  { icon: Globe, title: 'Subdomain & domain sendiri', text: `perusahaan.${config.baseDomain} atau kartu.perusahaananda.com, lengkap dengan logo & warna brand.` },
  { icon: IdCard, title: 'Kartu & ID badge untuk tim', text: 'Kartu nama dan ID badge potret dengan lubang lanyard — semuanya NFC, dikelola dari satu panel.' },
  { icon: Lock, title: 'Peran, izin & field terkunci', text: 'Atur siapa boleh mengubah apa. Jabatan & logo bisa dikunci agar tampilan tim seragam.' },
  { icon: ClipboardList, title: 'Lead ke sales yang tepat', text: 'Tahap pipeline & kolom lead sendiri, bagi lead otomatis, dan kirim ke sistem Anda lewat webhook.' },
]
const COMPARE = [
  ['Data bisa diperbarui setelah dicetak', false, true],
  ['Kontak tersimpan otomatis ke ponsel', false, true],
  ['Tahu siapa & kapan kartu dibuka', false, true],
  ['Lawan bicara bisa berbagi kontak balik', false, true],
  ['Aman saat kartu hilang', false, true],
  ['Tidak perlu cetak ulang saat ganti jabatan', false, true],
]
const RELIABLE = [
  { icon: Activity, title: 'Dipantau setiap menit', text: 'Aplikasi, tap kartu, dan situs dicek terus-menerus. Gangguan langsung diketahui tim kami — dan diumumkan terbuka di halaman status.' },
  { icon: DatabaseBackup, title: 'Backup harian di luar server', text: 'Database dicadangkan setiap malam ke penyimpanan terpisah dan disimpan 30 hari.' },
  { icon: Lock, title: 'Privasi sejak awal', text: 'IP pengunjung hanya disimpan sebagai hash, bio tidak diindeks mesin pencari kecuali Anda izinkan, dan data bisa diekspor atau dihapus kapan saja.' },
]
const faq = computed(() => [
  { q: 'Apakah orang yang saya temui perlu memasang aplikasi?', a: 'Tidak. Ponsel modern membaca NFC langsung dan membuka bio link di browser. Untuk ponsel tanpa NFC, cukup scan QR di kartu atau di layar ponsel Anda.' },
  { q: 'Apa beda kartu digital dan kartu fisik?', a: `Kartu digital gratis untuk setiap akun dan langsung aktif: QR di layar & tautan pendek, dengan ${num(digital.value.tap_quota)} tap dan ${digital.value.bio_pages} bio link. Kuota tidak di-reset bulanan; bila habis, tambah ${num(digital.value.topup_taps)} tap seharga ${rupiah(digital.value.topup_price)}. Kartu fisik NFC tanpa batas tap dan bisa punya sampai ${show.value?.physical_bio_pages ?? 3} bio link per kartu.` },
  { q: 'Bisakah saya mengubah data setelah kartu dicetak?', a: 'Bisa, kapan saja. Chip dan QR berisi alamat permanen kartu, sedangkan isinya (nama, jabatan, tautan, tema) diatur dari akun Anda dan langsung berlaku.' },
  { q: 'Berapa harga kartu fisik dan berapa lama sampai?', a: `Mulai ${rupiah(cardFrom.value)} per kartu (cetak penuh dua sisi, chip NFC + QR), lanyard mulai ${rupiah(show.value?.lanyard_from)}. Diproduksi setelah pembayaran, lalu dikirim reguler (3–5 hari kerja), ekspres (1–2 hari kerja), atau diambil sendiri.` },
  { q: 'Bayarnya pakai apa?', a: 'QRIS — semua e-wallet dan mobile banking. Tagihan berlaku 30 menit; bila lewat, cukup bayar lagi dan tagihan baru dibuat otomatis. Pesanan yang tidak dibayar dalam 3 hari dibatalkan otomatis.' },
  { q: 'Bisa untuk tim atau perusahaan?', a: 'Bisa. Paket Team dan Business dihitung per anggota per bulan, dengan uji coba gratis 14 hari: subdomain atau domain sendiri, kartu & ID badge untuk setiap anggota, peran & izin, Mode Event, dan pembagian lead ke sales.' },
  { q: 'Bagaimana jika kartu saya hilang?', a: 'Tandai kartu sebagai hilang dari aplikasi. Orang yang men-tap hanya melihat halaman netral tanpa data Anda, Anda diberi tahu waktu & kota tap-nya, dan Anda bisa meminta kartu pengganti dengan bio yang sama.' },
  { q: 'Apakah data saya aman, dan bisakah dihapus?', a: 'IP pengunjung hanya disimpan sebagai hash dan database dicadangkan setiap hari. Anda bisa mengekspor semua data kapan saja. Menghapus akun mencabut semua kartu seketika; data pribadi dihapus permanen 30 hari kemudian.' },
  { q: 'Bagaimana saya tahu layanan sedang normal?', a: 'Buka halaman status: kondisi setiap layanan, uptime 90 hari, serta info gangguan & jadwal maintenance diumumkan terbuka di sana dan diperbarui secara langsung.' },
])
</script>

<template>
  <div class="min-h-dvh bg-bg text-fg">
    <SiteHeader />

    <main>
      <!-- Hero -->
      <section class="mx-auto grid max-w-[1200px] items-center gap-12 px-4 pt-14 pb-20 sm:px-6 lg:grid-cols-[1.05fr_1fr] lg:pt-24">
        <div class="grid content-start gap-6">
          <span class="label !text-accent-text">Kartu nama NFC + bio link + pipeline koneksi</span>
          <h1 class="font-display text-[44px] leading-[1.02] font-bold tracking-[-.02em] sm:text-[60px]">Satu tap. Kontak tersimpan. Koneksi tercatat.</h1>
          <p class="max-w-[540px] text-lg text-muted">
            Mulai dengan kartu digital gratis hari ini, lalu pesan kartu fisik NFC premium. Bio link yang bisa Anda ubah
            kapan saja, kontak yang langsung tersimpan, dan setiap pertemuan tercatat untuk di-follow up.
          </p>
          <div class="flex flex-wrap gap-3">
            <AppButton size="lg" :href="signup">Mulai gratis <ArrowRight class="size-5" /></AppButton>
            <AppButton size="lg" variant="secondary" href="#kartu">Digital vs fisik</AppButton>
          </div>
          <ul class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted">
            <li class="flex items-center gap-2"><Check class="size-4 text-accent-text" /> Kartu digital gratis · {{ num(digital.tap_quota) }} tap</li>
            <li class="flex items-center gap-2"><Nfc class="size-4 text-accent-text" /> NFC + QR di setiap kartu</li>
            <li class="flex items-center gap-2"><Smartphone class="size-4 text-accent-text" /> iPhone & Android, tanpa aplikasi</li>
          </ul>
        </div>

        <div class="relative mx-auto w-full max-w-[520px] pb-10">
          <FlipCard v-if="show" :front="show.templates[0].preview.landscape.front" :back="show.templates[0].preview.landscape.back" :vars="show.variables" :qr="show.qr" :flipped="heroFlipped" />
          <AppSkeleton v-else class="!aspect-[91.6/60] !h-auto w-full" />
          <div class="absolute -bottom-2 left-2 flex items-center gap-3 rounded-md border border-line bg-surface px-4 py-3 text-sm sm:-left-6">
            <span class="grid size-9 place-items-center rounded-full bg-solid-ok text-on-solid"><Check class="size-5" /></span>
            <span class="grid"><strong class="font-semibold">Kontak tersimpan</strong><span class="text-muted">Rina Saputra · baru saja</span></span>
          </div>
          <div class="absolute -top-4 right-2 hidden items-center gap-3 rounded-md border border-line bg-surface px-4 py-3 text-sm sm:flex">
            <MapPin class="size-5 text-accent-text" />
            <span class="grid"><strong class="font-semibold">Profil Anda dibuka</strong><span class="text-muted">Jakarta · lewat NFC</span></span>
          </div>
        </div>
      </section>

      <!-- Nilai utama -->
      <section class="border-y border-line bg-surface">
        <div class="mx-auto grid max-w-[1200px] gap-px bg-line md:grid-cols-3">
          <article v-for="v in VALUES" :key="v.title" class="grid content-start gap-3 bg-surface px-6 py-10">
            <component :is="v.icon" class="size-7 text-accent-text" aria-hidden="true" />
            <h3 class="font-display text-xl font-bold">{{ v.title }}</h3>
            <p class="text-muted">{{ v.text }}</p>
          </article>
        </div>
      </section>

      <!-- Cara kerja -->
      <section id="cara-kerja" class="mx-auto grid max-w-[1200px] scroll-mt-20 gap-10 px-4 py-20 sm:px-6">
        <SectionHeading eyebrow="Cara kerja" title="Dari daftar ke koneksi pertama dalam hitungan menit" />
        <ol class="grid gap-6 md:grid-cols-3">
          <li v-for="s in STEPS" :key="s.n" class="grid content-start gap-3 border-t-2 border-accent pt-5">
            <span class="font-mono text-sm text-accent-text">{{ s.n }}</span>
            <h3 class="font-display text-xl font-bold">{{ s.title }}</h3>
            <p class="text-muted">{{ s.text }}</p>
          </li>
        </ol>
      </section>

      <!-- Kartu digital vs fisik -->
      <section id="kartu" class="scroll-mt-20 border-t border-line bg-surface">
        <div class="mx-auto grid max-w-[1200px] gap-10 px-4 py-20 sm:px-6">
          <SectionHeading
            eyebrow="Dua cara berbagi"
            title="Mulai digital hari ini, tambah kartu fisik saat siap"
            text="Kartu digital dan kartu fisik punya paket sendiri-sendiri. Keduanya membuka bio link yang sama cantiknya, menyimpan kontak dengan satu ketukan, dan mencatat setiap koneksi."
          />
          <div class="grid gap-4 md:grid-cols-2">
            <article class="grid content-start gap-4 rounded-lg border border-line bg-bg p-6">
              <span class="flex items-center gap-3"><Smartphone class="size-7 text-accent-text" aria-hidden="true" /><h3 class="font-display text-2xl font-bold">Kartu digital</h3></span>
              <p class="font-display text-[32px] font-bold">Gratis <span class="text-base font-normal text-muted">untuk setiap akun</span></p>
              <ul class="grid gap-2 text-sm">
                <li class="flex gap-2"><Check class="size-5 flex-none text-ok" aria-hidden="true" /> Aktif seketika: QR di layar ponsel & tautan pendek @nama</li>
                <li class="flex gap-2"><Check class="size-5 flex-none text-ok" aria-hidden="true" /> {{ num(digital.tap_quota) }} tap — tidak di-reset bulanan</li>
                <li class="flex gap-2"><Check class="size-5 flex-none text-ok" aria-hidden="true" /> {{ digital.bio_pages }} bio link dengan semua blok & tema</li>
                <li class="flex gap-2"><Check class="size-5 flex-none text-ok" aria-hidden="true" /> Butuh lebih? Tambah {{ num(digital.topup_taps) }} tap seharga {{ rupiah(digital.topup_price) }}</li>
              </ul>
              <AppButton variant="secondary" :href="signup">Buat kartu digital</AppButton>
            </article>
            <article class="grid content-start gap-4 rounded-lg border border-accent bg-bg p-6">
              <span class="flex items-center gap-3"><Nfc class="size-7 text-accent-text" aria-hidden="true" /><h3 class="font-display text-2xl font-bold">Kartu fisik NFC</h3></span>
              <p class="font-display text-[32px] font-bold">{{ cardFrom ? `Mulai ${rupiah(cardFrom)}` : '—' }} <span class="text-base font-normal text-muted">per kartu</span></p>
              <ul class="grid gap-2 text-sm">
                <li class="flex gap-2"><Check class="size-5 flex-none text-ok" aria-hidden="true" /> Tempel ke ponsel — kesan pertama yang diingat</li>
                <li class="flex gap-2"><Check class="size-5 flex-none text-ok" aria-hidden="true" /> Tanpa batas tap</li>
                <li class="flex gap-2"><Check class="size-5 flex-none text-ok" aria-hidden="true" /> Sampai {{ show?.physical_bio_pages ?? 3 }} bio link per kartu (mis. Kerja / Pameran / Personal)</li>
                <li class="flex gap-2"><Check class="size-5 flex-none text-ok" aria-hidden="true" /> Masuk ke akun cukup dengan menempelkan kartu + kode</li>
              </ul>
              <AppButton :href="signup">Pesan kartu fisik</AppButton>
            </article>
          </div>
        </div>
      </section>

      <!-- Template -->
      <section id="template" class="scroll-mt-20 border-t border-line bg-surface-2">
        <div class="mx-auto grid max-w-[1200px] gap-10 px-4 py-20 sm:px-6">
          <SectionHeading
            eyebrow="8 template default"
            title="Minimalis, siap cetak, enak dipandang"
            text="Setiap template sudah lolos pemeriksaan cetak — safe zone 4 mm, bleed 3 mm, QR minimal 15 mm, dan aman untuk lubang lanyard. QR, ikon NFC, dan logo selalu di posisi yang mudah ditemukan."
          />
          <TemplateGallery v-if="show" :templates="show.templates" :vars="show.variables" :qr="show.qr" />
          <div v-else class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4"><AppSkeleton v-for="i in 8" :key="i" class="!h-44" /></div>
          <div class="flex flex-wrap items-center gap-4">
            <AppButton :href="signup"><Palette class="size-5" /> Desain sendiri di Design Studio</AppButton>
            <span class="text-sm text-muted">Ubah teks, warna, logo, dan tata letak — atau mulai dari kanvas kosong.</span>
          </div>
        </div>
      </section>

      <!-- Editor -->
      <section id="editor" class="mx-auto grid max-w-[1200px] scroll-mt-20 gap-10 px-4 py-20 sm:px-6">
        <SectionHeading
          eyebrow="Editor kartu & lanyard"
          title="Desain sendiri, langsung di browser — cobalah di sini"
          text="Editor yang sama dengan di aplikasi: pilih template, geser teks & logo, atur lapisan, lalu desain lanyard yang serasi. Pemeriksaan cetak berjalan otomatis, jadi hasil cetak sesuai yang Anda lihat."
        />
        <EditorShowcase v-if="show?.editor" :templates="show.templates" :vars="show.variables" :qr="show.qr" :editor="show.editor" />
        <AppSkeleton v-else class="!h-[560px]" />
        <ul class="grid gap-3 text-sm text-muted sm:grid-cols-2 lg:grid-cols-4">
          <li class="flex gap-2"><Check class="size-5 flex-none text-ok" /> Teks, bentuk, ikon, logo &amp; gambar</li>
          <li class="flex gap-2"><Check class="size-5 flex-none text-ok" /> Safe zone, bleed &amp; ukuran QR dicek otomatis</li>
          <li class="flex gap-2"><Check class="size-5 flex-none text-ok" /> Lanyard 15/20/25 mm dengan harga langsung</li>
          <li class="flex gap-2"><Check class="size-5 flex-none text-ok" /> Brand kit dipakai bersama kartu &amp; lanyard</li>
        </ul>
      </section>

      <!-- Bio link -->
      <section id="bio-link" class="scroll-mt-20 border-t border-line bg-surface-2">
        <div class="mx-auto grid max-w-[1200px] gap-10 px-4 py-20 sm:px-6">
          <SectionHeading
            eyebrow="Bio link per kartu"
            title="Halaman yang terbuka saat kartu di-tap — dan sepenuhnya milik Anda"
            text="Susun halaman dari blok: Simpan ke Kontak HP, kontak & tautan, tombol, teks, video, pembatas, dan formulir. Atur tema, warna aksen, bentuk foto, gaya tombol, dan font — lihat hasilnya langsung sebelum disimpan."
          />
          <BioShowcase v-if="show" :catalog="show.bio" :sample="show.sample">
            <ul class="grid gap-2 text-sm text-muted sm:grid-cols-2">
              <li class="flex gap-2"><Check class="size-5 flex-none text-ok" /> 8 tema selaras template kartu</li>
              <li class="flex gap-2"><Check class="size-5 flex-none text-ok" /> 15 warna aksen, kontras dijaga otomatis</li>
              <li class="flex gap-2"><Check class="size-5 flex-none text-ok" /> Video YouTube, TikTok, Vimeo & Spotify</li>
              <li class="flex gap-2"><Check class="size-5 flex-none text-ok" /> Formulir pendaftaran langsung jadi lead</li>
            </ul>
          </BioShowcase>
          <AppSkeleton v-else class="!h-[640px]" />
        </div>
      </section>

      <!-- Fitur -->
      <section id="fitur" class="scroll-mt-20 border-t border-line bg-surface">
        <div class="mx-auto grid max-w-[1200px] gap-10 px-4 py-20 sm:px-6">
          <SectionHeading eyebrow="Lebih dari kartu nama" title="Semua yang terjadi setelah jabat tangan" />
          <div class="grid gap-x-8 gap-y-10 sm:grid-cols-2 lg:grid-cols-4">
            <article v-for="f in FEATURES" :key="f.title" class="grid content-start gap-2">
              <span class="grid size-11 place-items-center rounded-md bg-surface-2"><component :is="f.icon" class="size-5 text-accent-text" aria-hidden="true" /></span>
              <h3 class="mt-2 text-lg font-semibold">{{ f.title }}</h3>
              <p class="text-sm text-muted">{{ f.text }}</p>
            </article>
          </div>
        </div>
      </section>

      <!-- Perusahaan -->
      <section id="perusahaan" class="mx-auto grid max-w-[1200px] scroll-mt-20 gap-10 px-4 py-20 sm:px-6">
        <SectionHeading
          eyebrow="Untuk tim & perusahaan"
          title="Satu tampilan brand untuk seluruh tim — dan semua lead masuk ke satu tempat"
          text="Workspace perusahaan dengan anggota, peran, dan kartu untuk setiap orang. Cocok untuk tim sales, agensi, dan perusahaan yang rutin ikut pameran."
        />
        <div class="grid gap-x-8 gap-y-10 sm:grid-cols-2 lg:grid-cols-4">
          <article v-for="f in COMPANY" :key="f.title" class="grid content-start gap-2">
            <span class="grid size-11 place-items-center rounded-md bg-surface-2"><component :is="f.icon" class="size-5 text-accent-text" aria-hidden="true" /></span>
            <h3 class="mt-2 text-lg font-semibold">{{ f.title }}</h3>
            <p class="text-sm text-muted">{{ f.text }}</p>
          </article>
        </div>
        <div class="flex flex-wrap items-center gap-4">
          <AppButton :href="signup">Coba gratis 14 hari <ArrowRight class="size-5" /></AppButton>
          <a href="#harga" class="inline-flex min-h-11 items-center text-sm font-semibold text-accent-text underline">Lihat harga per anggota</a>
        </div>
      </section>

      <!-- Pesan kartu & lanyard -->
      <section id="pesan" class="scroll-mt-20 border-t border-line bg-surface-2">
        <div class="mx-auto grid max-w-[1200px] gap-10 px-4 py-20 sm:px-6">
          <SectionHeading eyebrow="Pesan langsung dari aplikasi" title="Kartu, ID badge, dan lanyard — dicetak sesuai desain Anda" />
          <div v-if="show" class="grid gap-4 md:grid-cols-3">
            <article v-for="c in show.card_products" :key="c.name" class="grid content-start gap-2 rounded-lg border border-line bg-surface p-5">
              <h3 class="text-lg font-semibold">{{ c.name }}</h3>
              <p class="font-display text-2xl font-bold">{{ rupiah(c.price) }} <span class="text-sm font-normal text-muted">/ kartu</span></p>
              <p class="text-sm text-muted">{{ c.description }}</p>
            </article>
          </div>
          <div v-else class="grid gap-4 md:grid-cols-3"><AppSkeleton v-for="i in 3" :key="i" class="!h-36" /></div>
          <ul class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <li class="flex gap-3"><Palette class="size-5 flex-none text-accent-text" aria-hidden="true" /><span><strong class="block">Lanyard serasi</strong><span class="text-muted">15/20/25 mm, mulai {{ rupiah(show?.lanyard_from) }} per pcs</span></span></li>
            <li class="flex gap-3"><Wallet class="size-5 flex-none text-accent-text" aria-hidden="true" /><span><strong class="block">Bayar QRIS</strong><span class="text-muted">Semua e-wallet & mobile banking, kode promo didukung</span></span></li>
            <li class="flex gap-3"><Truck class="size-5 flex-none text-accent-text" aria-hidden="true" /><span><strong class="block">Kirim atau ambil</strong><span class="text-muted">Reguler 3–5 hari, ekspres 1–2 hari kerja</span></span></li>
            <li class="flex gap-3"><Blocks class="size-5 flex-none text-accent-text" aria-hidden="true" /><span><strong class="block">Merchandise</strong><span class="text-muted">{{ merch }}</span></span></li>
          </ul>
        </div>
      </section>

      <!-- Perbandingan -->
      <section class="mx-auto grid max-w-[900px] gap-8 px-4 py-20 sm:px-6">
        <SectionHeading center eyebrow="Kenapa beralih" title="Kartu kertas berhenti bekerja setelah diberikan" />
        <div class="overflow-x-auto rounded-lg border border-line">
          <table class="w-full text-left text-sm">
            <thead class="bg-surface-2">
              <tr><th class="px-4 py-3 font-medium text-muted"></th><th class="px-4 py-3 text-center font-medium text-muted">Kartu kertas</th><th class="px-4 py-3 text-center font-semibold text-accent-text">TapCard</th></tr>
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
      </section>

      <!-- Harga -->
      <section id="harga" class="scroll-mt-20 border-t border-line bg-surface-2">
        <div class="mx-auto grid max-w-[1200px] gap-10 px-4 py-20 sm:px-6">
          <SectionHeading eyebrow="Harga" title="Mulai gratis, upgrade saat jaringan Anda tumbuh" text="Harga langganan aplikasi. Kartu digital gratis termasuk di semua paket; kartu fisik & lanyard dipesan terpisah sesuai desain Anda." />
          <PricingTable v-if="show" :plans="show.plans" :company-plans="show.company_plans" :physical-bio-pages="show.physical_bio_pages" />
          <div v-else class="grid gap-4 md:grid-cols-2"><AppSkeleton v-for="i in 2" :key="i" class="!h-80" /></div>
          <div class="flex flex-wrap items-center gap-4 rounded-md border border-line bg-surface px-5 py-4">
            <Blocks class="size-6 flex-none text-accent-text" aria-hidden="true" />
            <div class="grid min-w-0 flex-1 gap-0.5">
              <span class="font-semibold">Add-on Integrasi CRM <span class="ml-1 rounded-full bg-surface-2 px-2 py-0.5 text-xs font-medium text-info">Segera hadir</span></span>
              <span class="text-sm text-muted">Kirim koneksi & lead otomatis ke Google Sheets dan HubSpot. Dibayar terpisah dari paket — pasang hanya bila perlu.</span>
            </div>
          </div>
        </div>
      </section>

      <!-- Keandalan & status langsung -->
      <section id="keandalan" class="mx-auto grid max-w-[1200px] scroll-mt-20 gap-10 px-4 py-20 sm:px-6 lg:grid-cols-[1fr_1.1fr] lg:items-start">
        <div class="grid content-start gap-8">
          <SectionHeading eyebrow="Keandalan" title="Setiap tap harus berhasil, kapan pun" text="Kami memantau layanan terus-menerus dan mengumumkan setiap gangguan secara terbuka — lihat sendiri angkanya di samping." />
          <ul class="grid gap-6">
            <li v-for="r in RELIABLE" :key="r.title" class="flex gap-4">
              <span class="grid size-11 flex-none place-items-center rounded-md bg-surface-2"><component :is="r.icon" class="size-5 text-accent-text" aria-hidden="true" /></span>
              <span class="grid gap-1"><strong class="text-lg">{{ r.title }}</strong><span class="text-sm text-muted">{{ r.text }}</span></span>
            </li>
          </ul>
        </div>
        <LiveStatus />
      </section>

      <!-- FAQ -->
      <section id="faq" class="scroll-mt-20 border-t border-line">
        <div class="mx-auto grid max-w-[900px] gap-8 px-4 py-20 sm:px-6">
          <SectionHeading eyebrow="FAQ" title="Pertanyaan yang sering diajukan" />
          <FaqList :items="faq" />
        </div>
      </section>

      <!-- CTA akhir -->
      <section class="border-t border-line">
        <div class="mx-auto grid max-w-[1200px] justify-items-center gap-6 px-4 py-20 text-center sm:px-6">
          <QrCode class="size-10 text-accent-text" aria-hidden="true" />
          <h2 class="max-w-[720px] font-display text-[36px] leading-[1.05] font-bold tracking-[-.015em] sm:text-[48px]">Buat kesan pertama yang tidak terlupakan.</h2>
          <p class="max-w-[520px] text-lg text-muted">Daftar gratis, kartu digital Anda langsung aktif — bagikan bio link Anda hari ini.</p>
          <AppButton size="lg" :href="signup">Mulai gratis <ArrowRight class="size-5" /></AppButton>
        </div>
      </section>
    </main>

    <SiteFooter />
  </div>
</template>
