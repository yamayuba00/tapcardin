<script setup>
// Landing page (domain utama): menjual nilai produk — kartu fisik + bio link per kartu + tindak lanjut.
// Template & bio link dirender dengan komponen yang sama dengan produk (data dari GET /api/showcase).
import { onBeforeUnmount, onMounted, ref } from 'vue'
import {
  ArrowRight, Bell, Blocks, ChartColumn, Check, Download, Layers, MapPin, Nfc, Palette, Pencil, QrCode, ShieldCheck, Smartphone, UserPlus, Users, X, Zap,
} from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import BioShowcase from '@/components/marketing/BioShowcase/index.vue'
import EditorShowcase from '@/components/marketing/EditorShowcase/index.vue'
import FaqList from '@/components/marketing/FaqList/index.vue'
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
const signup = `${config.appUrl}/register`

// Kartu hero membalik sendiri, kecuali pengguna memilih gerak dikurangi.
const heroFlipped = ref(false)
let timer = null
onMounted(() => {
  if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) timer = setInterval(() => (heroFlipped.value = !heroFlipped.value), 3800)
})
onBeforeUnmount(() => clearInterval(timer))

const VALUES = [
  { icon: Download, title: '2 detik, bukan 2 menit', text: 'Satu tombol Simpan Kontak: nama, nomor, email, dan foto langsung masuk buku kontak. Tidak ada salah ketik nomor lagi.' },
  { icon: Pencil, title: 'Cetak sekali, perbarui selamanya', text: 'Ganti jabatan, nomor, atau tautan kapan saja. Alamat di chip & QR tidak berubah, jadi kartu tidak perlu dicetak ulang.' },
  { icon: UserPlus, title: 'Setiap tap jadi peluang', text: 'Lawan bicara bisa membagikan kontaknya balik lewat Tukar Kontak. Semuanya tercatat rapi untuk ditindaklanjuti.' },
]
const STEPS = [
  { n: '01', title: 'Pilih desain', text: '8 template siap cetak, atau desain sendiri di Design Studio — lengkap dengan posisi lanyard.' },
  { n: '02', title: 'Langsung pakai versi digital', text: 'Begitu daftar, kartu digital Anda aktif: QR di layar & tautan @nama. Kartu fisik tinggal ditempel ke ponsel saat tiba.' },
  { n: '03', title: 'Tempel & terhubung', text: 'Orang lain cukup menempelkan ponselnya atau scan QR. Tanpa aplikasi, di iPhone maupun Android.' },
]
const FEATURES = [
  { icon: Layers, title: 'Satu kartu, satu bio link', text: 'Kartu kantor dan kartu pribadi tampil berbeda — masing-masing punya bio, tema, dan tautan sendiri.' },
  { icon: Users, title: 'Tukar Kontak', text: 'Form singkat dengan persetujuan tercatat. Kontak ganda otomatis digabung berdasarkan nomor & email.' },
  { icon: Zap, title: 'Pipeline koneksi', text: 'Tahapan, catatan, tag, dan ekspor CSV. Tahu siapa yang harus di-follow up minggu ini.' },
  { icon: ChartColumn, title: 'Analitik tap', text: 'Berapa kali kartu dibuka, dari kota mana, lewat NFC atau QR, dan tautan mana yang paling diklik.' },
  { icon: Bell, title: 'Notifikasi realtime', text: 'Kabar seketika saat seseorang membuka bio Anda atau mengirim kontaknya.' },
  { icon: ShieldCheck, title: 'Kartu hilang? Aman', text: 'Tandai hilang: tap berikutnya hanya melihat halaman netral, Anda diberi tahu lokasinya, dan bisa minta kartu pengganti.' },
  { icon: Palette, title: 'Design Studio', text: 'Teks, bentuk, logo, snap ke garis panduan, dan pemeriksaan cetak otomatis (safe zone, bleed, ukuran QR).' },
  { icon: Smartphone, title: 'Tanpa aplikasi', text: 'Bekerja di browser ponsel mana pun. NFC untuk tap, QR untuk ponsel yang tidak punya NFC.' },
]
const COMPARE = [
  ['Data bisa diperbarui setelah dicetak', false, true],
  ['Kontak tersimpan otomatis ke ponsel', false, true],
  ['Tahu siapa & kapan kartu dibuka', false, true],
  ['Lawan bicara bisa berbagi kontak balik', false, true],
  ['Aman saat kartu hilang', false, true],
  ['Tidak perlu cetak ulang saat ganti jabatan', false, true],
]
const FAQ = [
  { q: 'Apakah orang yang saya temui perlu memasang aplikasi?', a: 'Tidak. Ponsel modern membaca NFC langsung dan membuka bio link di browser. Untuk ponsel tanpa NFC, cukup scan QR di sisi belakang kartu.' },
  { q: 'Bisakah saya mengubah data setelah kartu dicetak?', a: 'Bisa, kapan saja. Chip dan QR berisi alamat permanen kartu, sedangkan isinya (nama, jabatan, tautan, tema) diatur dari akun Anda dan langsung berlaku.' },
  { q: 'Saya punya beberapa kartu. Apakah bio link-nya sama?', a: 'Tidak harus. Setiap kartu punya bio link sendiri — misalnya kartu kantor bertema korporat dan kartu komunitas bertema kreatif. Saat kartu baru diaktifkan, bio Anda yang terakhir disalin agar tidak perlu mengetik ulang.' },
  { q: 'Belum punya kartu, atau kartunya ketinggalan?', a: 'Tetap bisa. Setiap akun mendapat kartu digital gratis: tunjukkan QR dari menu Bagikan di ponsel, atau kirim tautan pendek tap…/@nama lewat WhatsApp. Bio, Simpan Kontak, dan Tukar Kontak bekerja sama persis — dan tidak memakai kuota kartu paket Anda.' },
  { q: 'Bagaimana jika kartu saya hilang?', a: 'Tandai kartu sebagai hilang dari aplikasi. Orang yang men-tap hanya melihat halaman netral tanpa data Anda, Anda diberi tahu waktu & kota tap-nya, dan Anda bisa meminta kartu pengganti dengan bio yang sama.' },
  { q: 'Apakah data saya aman?', a: 'Alamat IP pengunjung hanya disimpan dalam bentuk hash, bio tidak diindeks mesin pencari kecuali Anda izinkan, dan Anda bisa mengekspor atau menghapus semua data kapan saja.' },
  { q: 'Bisa untuk tim atau perusahaan?', a: 'Paket Tim & Perusahaan (subdomain sendiri, kartu untuk setiap anggota, branding, laporan lead) sedang kami siapkan. Daftar sekarang dengan akun personal; akun Anda bisa diundang ke workspace perusahaan nanti.' },
]
</script>

<template>
  <div class="min-h-dvh bg-bg text-fg">
    <SiteHeader />

    <main>
      <!-- Hero -->
      <section class="mx-auto grid max-w-[1200px] items-center gap-12 px-4 pt-14 pb-20 sm:px-6 lg:grid-cols-[1.05fr_1fr] lg:pt-24">
        <div class="grid content-start gap-6">
          <span class="label !text-accent-text">Kartu nama NFC + bio link</span>
          <h1 class="font-display text-[44px] leading-[1.02] font-bold tracking-[-.02em] sm:text-[60px]">Satu tap. Kontak tersimpan. Koneksi tercatat.</h1>
          <p class="max-w-[540px] text-lg text-muted">
            TapCard menggabungkan kartu fisik premium dengan bio link yang bisa Anda ubah kapan saja.
            Tempelkan ke ponsel atau scan QR — tanpa aplikasi, tanpa salah ketik nomor.
          </p>
          <div class="flex flex-wrap gap-3">
            <AppButton size="lg" :href="signup">Mulai gratis <ArrowRight class="size-5" /></AppButton>
            <AppButton size="lg" variant="secondary" href="#template">Lihat template</AppButton>
          </div>
          <ul class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted">
            <li class="flex items-center gap-2"><Nfc class="size-4 text-accent-text" /> NFC + QR di setiap kartu</li>
            <li class="flex items-center gap-2"><Smartphone class="size-4 text-accent-text" /> iPhone & Android</li>
            <li class="flex items-center gap-2"><Check class="size-4 text-accent-text" /> Kartu digital gratis — tanpa menunggu kartu fisik</li>
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
        <SectionHeading eyebrow="Cara kerja" title="Dari kotak ke koneksi pertama dalam hitungan menit" />
        <ol class="grid gap-6 md:grid-cols-3">
          <li v-for="s in STEPS" :key="s.n" class="grid content-start gap-3 border-t-2 border-accent pt-5">
            <span class="font-mono text-sm text-accent-text">{{ s.n }}</span>
            <h3 class="font-display text-xl font-bold">{{ s.title }}</h3>
            <p class="text-muted">{{ s.text }}</p>
          </li>
        </ol>
      </section>

      <!-- Template -->
      <section id="template" class="scroll-mt-20 border-t border-line bg-surface-2">
        <div class="mx-auto grid max-w-[1200px] gap-10 px-4 py-20 sm:px-6">
          <SectionHeading
            eyebrow="8 template default"
            title="Minimalis, siap cetak, enak dipandang"
            text="Setiap template sudah lolos pemeriksaan cetak — safe zone 4 mm, bleed 3 mm, QR minimal 15 mm, dan aman untuk lubang lanyard. QR, ikon NFC, dan logo TapCard selalu di posisi yang mudah ditemukan."
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
          text="Setiap kartu punya bio link sendiri. Atur tema, warna aksen, tata letak, bentuk foto, gaya tombol & tautan, dan font — lihat hasilnya langsung sebelum disimpan."
        />
        <BioShowcase v-if="show" :catalog="show.bio" :sample="show.sample">
          <ul class="grid gap-2 text-sm text-muted sm:grid-cols-2">
            <li class="flex gap-2"><Check class="size-5 flex-none text-ok" /> 8 tema selaras template kartu</li>
            <li class="flex gap-2"><Check class="size-5 flex-none text-ok" /> 15 warna aksen, kontras dijaga otomatis</li>
            <li class="flex gap-2"><Check class="size-5 flex-none text-ok" /> Simpan Kontak (vCard) dengan foto</li>
            <li class="flex gap-2"><Check class="size-5 flex-none text-ok" /> WhatsApp, telepon, email sekali ketuk</li>
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
          <SectionHeading eyebrow="Harga" title="Mulai gratis, upgrade saat jaringan Anda tumbuh" text="Harga langganan aplikasi; kartu fisik dipesan terpisah sesuai desain Anda." />
          <PricingTable v-if="show" :plans="show.plans" />
          <div v-else class="grid gap-4 md:grid-cols-3"><AppSkeleton v-for="i in 3" :key="i" class="!h-80" /></div>
          <div class="flex flex-wrap items-center gap-4 rounded-md border border-line bg-surface px-5 py-4">
            <Blocks class="size-6 flex-none text-accent-text" aria-hidden="true" />
            <div class="grid min-w-0 flex-1 gap-0.5">
              <span class="font-semibold">Add-on Integrasi CRM <span class="ml-1 rounded-full bg-surface-2 px-2 py-0.5 text-xs font-medium text-info">Segera hadir</span></span>
              <span class="text-sm text-muted">Kirim koneksi & lead otomatis ke Google Sheets dan HubSpot. Dibayar terpisah dari paket — pasang hanya bila perlu.</span>
            </div>
          </div>
        </div>
      </section>

      <!-- FAQ -->
      <section id="faq" class="mx-auto grid max-w-[900px] scroll-mt-20 gap-8 px-4 py-20 sm:px-6">
        <SectionHeading eyebrow="FAQ" title="Pertanyaan yang sering diajukan" />
        <FaqList :items="FAQ" />
      </section>

      <!-- CTA akhir -->
      <section class="border-t border-line">
        <div class="mx-auto grid max-w-[1200px] justify-items-center gap-6 px-4 py-20 text-center sm:px-6">
          <QrCode class="size-10 text-accent-text" aria-hidden="true" />
          <h2 class="max-w-[720px] font-display text-[36px] leading-[1.05] font-bold tracking-[-.015em] sm:text-[48px]">Buat kesan pertama yang tidak terlupakan.</h2>
          <p class="max-w-[520px] text-lg text-muted">Daftar gratis, pilih template, dan bagikan bio link Anda hari ini.</p>
          <AppButton size="lg" :href="signup">Mulai gratis <ArrowRight class="size-5" /></AppButton>
        </div>
      </section>
    </main>

    <SiteFooter />
  </div>
</template>
