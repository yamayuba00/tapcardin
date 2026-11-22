<?php

namespace App\Support\Marketing;

use App\Http\Controllers\Marketing\ShowcaseController;

/**
 * Isi landing page yang dipakai bersama oleh halaman (Vue), render server (SSR), dan mesin pencari (meta + JSON-LD).
 * Satu sumber: FAQ di halaman dan FAQPage JSON-LD berasal dari fungsi yang sama, harga dari tabel paket & produk.
 */
final class Landing
{
    public const TITLE = 'TapCard — Kartu nama NFC, QR & bio link untuk Anda dan tim';

    public const DESCRIPTION = 'Bagikan kontak dengan satu tap atau scan QR, tanpa aplikasi. Kartu digital gratis, kartu NFC, bio link yang bisa diubah, dan lead tercatat untuk tim.';

    public const OG_IMAGE = '/og-tapcard.png';

    /**
     * Data awal yang disisipkan ke halaman (kecil): cukup untuk merender seluruh teks & harga tanpa menunggu API.
     * Galeri template, editor, dan contoh bio tetap diambil klien dari /api/showcase.
     */
    public static function critical(): array
    {
        $s = ShowcaseController::data();
        $hero = $s['templates'][0] ?? null;

        return [
            'hero' => $hero ? ['key' => $hero['key'], 'name' => $hero['name'], 'preview' => ['landscape' => $hero['preview']['landscape']]] : null,
            'template_count' => count($s['templates']),
            'variables' => $s['variables'],
            'sample' => $s['sample'], // profil contoh: hasil tap di visual hero
            'qr' => $s['qr'],
            'plans' => $s['plans'],
            'company_plans' => $s['company_plans'],
            'digital_card' => $s['digital_card'],
            'physical_bio_pages' => $s['physical_bio_pages'],
            'card_products' => $s['card_products'],
            'lanyard_from' => $s['lanyard_from'],
            'merch' => $s['merch'],
            'faq' => self::faq($s),
        ];
    }

    /** @return list<array{q:string,a:string}> */
    public static function faq(array $s): array
    {
        $d = $s['digital_card'];
        $cardFrom = $s['card_products'] ? min(array_column($s['card_products'], 'price')) : null;
        $company = $s['company_plans'];
        $trial = $company[0]['trial_days'] ?? 14;
        $tiers = implode(', ', array_map(fn ($p) => $p['name'].($p['cards'] ? ' (maks. '.self::num($p['cards']).' kartu aktif)' : ''), $company));

        return [
            ['q' => 'Apakah orang yang saya temui perlu memasang aplikasi?', 'a' => 'Tidak. Ponsel modern membaca NFC langsung dan membuka profil Anda di browser. Ponsel tanpa NFC cukup scan QR di kartu atau di layar ponsel Anda.'],
            ['q' => 'HP apa saja yang bisa membaca kartu?', 'a' => 'iPhone XS ke atas membaca NFC tanpa aplikasi, begitu juga Android yang NFC-nya aktif. Semua ponsel berkamera bisa scan QR yang tercetak di setiap kartu.'],
            ['q' => 'Apa beda kartu digital dan kartu fisik?', 'a' => 'Kartu digital gratis untuk setiap akun dan langsung aktif: QR di layar & tautan pendek, dengan '.self::num($d['tap_quota']).' tap dan '.$d['bio_pages'].' bio link. Kuota tidak di-reset bulanan; bila habis, tambah '.self::num($d['topup_taps']).' tap seharga '.self::rupiah($d['topup_price']).'. Kartu fisik NFC tanpa batas tap dan bisa punya sampai '.$s['physical_bio_pages'].' bio link per kartu.'],
            ['q' => 'Bisakah saya mengubah data setelah kartu dicetak?', 'a' => 'Bisa, kapan saja. Chip dan QR berisi alamat permanen kartu, sedangkan isinya (nama, jabatan, tautan, tema) diatur dari akun Anda dan langsung berlaku.'],
            ['q' => 'Apakah kartu bekerja tanpa internet?', 'a' => 'Kartu tidak memakai baterai dan tidak perlu internet. Chip dan QR hanya berisi alamat profil Anda, jadi ponsel yang membacanya perlu internet untuk membuka profil dan menyimpan kontak.'],
            ['q' => 'Berapa harga kartu fisik dan berapa lama sampai?', 'a' => ($cardFrom ? 'Mulai '.self::rupiah($cardFrom).' per kartu (cetak penuh dua sisi, chip NFC + QR)' : 'Harga per kartu tampil saat memesan').($s['lanyard_from'] ? ', lanyard mulai '.self::rupiah((int) $s['lanyard_from']) : '').'. Diproduksi setelah pembayaran, lalu dikirim reguler (3–5 hari kerja), ekspres (1–2 hari kerja), atau diambil sendiri.'],
            ['q' => 'Bayarnya pakai apa?', 'a' => 'QRIS — semua e-wallet dan mobile banking. Halaman bayar menampilkan sisa waktu dan status pembayaran secara langsung, dan kuitansi PDF bisa diunduh setelah lunas.'],
            ['q' => 'Bisa untuk tim, perusahaan, atau komunitas?', 'a' => 'Bisa. Paket perusahaan dibayar per 6 bulan dengan harga tetap — '.($tiers ?: 'beberapa tingkat').' — jumlah anggota tidak dibatasi, dan ada uji coba gratis '.$trial.' hari. Anda mendapat subdomain sendiri, kartu & ID badge untuk anggota, check-in event, serta domain kustom dan webhook di paket Business.'],
            ['q' => 'Bagaimana jika kartu saya hilang?', 'a' => 'Tandai kartu sebagai hilang dari aplikasi. Orang yang men-tap hanya melihat halaman netral tanpa data Anda, Anda diberi tahu waktu & kota tap-nya, dan Anda bisa meminta kartu pengganti dengan bio yang sama.'],
            ['q' => 'Apakah data saya aman, dan bisakah dihapus?', 'a' => 'IP pengunjung hanya disimpan sebagai hash dan database dicadangkan setiap hari. Anda bisa mengekspor semua data kapan saja. Menghapus akun mencabut semua kartu seketika; data pribadi dihapus permanen 30 hari kemudian.'],
            ['q' => 'Bagaimana saya tahu layanan sedang normal?', 'a' => 'Buka halaman status: kondisi setiap layanan, uptime 90 hari, serta info gangguan & jadwal maintenance diumumkan terbuka di sana dan diperbarui secara langsung.'],
        ];
    }

    /** schema.org @graph untuk landing page. */
    public static function jsonLd(array $c, string $url): array
    {
        $org = ['@type' => 'Organization', '@id' => "$url/#organization", 'name' => 'TapCard', 'url' => "$url/", 'logo' => "$url/icons/icon-512.png"];
        $offers = array_map(fn ($p) => ['@type' => 'Offer', 'price' => (string) $p['price'], 'priceCurrency' => 'IDR', 'name' => $p['name'], 'availability' => 'https://schema.org/InStock'], $c['card_products']);
        $prices = array_column($c['card_products'], 'price');
        $plans = [
            ...array_map(fn ($p) => ['@type' => 'Offer', 'name' => 'Paket '.$p['name'], 'price' => (string) $p['price_monthly'], 'priceCurrency' => 'IDR',
                'priceSpecification' => ['@type' => 'UnitPriceSpecification', 'price' => (string) $p['price_monthly'], 'priceCurrency' => 'IDR', 'unitText' => 'bulan']], $c['plans']),
            ...array_map(fn ($p) => ['@type' => 'Offer', 'name' => 'Paket perusahaan '.$p['name'], 'price' => (string) $p['price_semiannual'], 'priceCurrency' => 'IDR',
                'priceSpecification' => ['@type' => 'UnitPriceSpecification', 'price' => (string) $p['price_semiannual'], 'priceCurrency' => 'IDR', 'unitText' => '6 bulan']], $c['company_plans']),
        ];

        return ['@context' => 'https://schema.org', '@graph' => array_values(array_filter([
            $org,
            ['@type' => 'WebSite', '@id' => "$url/#website", 'name' => 'TapCard', 'url' => "$url/", 'inLanguage' => 'id-ID', 'publisher' => ['@id' => "$url/#organization"]],
            $prices ? ['@type' => 'Product', 'name' => 'Kartu nama NFC TapCard', 'description' => 'Kartu nama PVC cetak penuh dengan chip NFC dan QR yang membuka profil & menyimpan kontak tanpa aplikasi.',
                'brand' => ['@id' => "$url/#organization"], 'image' => $url.self::OG_IMAGE,
                'offers' => ['@type' => 'AggregateOffer', 'priceCurrency' => 'IDR', 'lowPrice' => (string) min($prices), 'highPrice' => (string) max($prices), 'offerCount' => count($prices), 'offers' => $offers]] : null,
            $plans ? ['@type' => 'SoftwareApplication', 'name' => 'TapCard', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web, iOS, Android', 'url' => "$url/", 'offers' => $plans] : null,
            ['@type' => 'FAQPage', 'mainEntity' => array_map(fn ($f) => ['@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]], $c['faq'])],
        ]))];
    }

    public static function rupiah(int $n): string
    {
        return 'Rp'.number_format($n, 0, ',', '.');
    }

    private static function num(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }
}
