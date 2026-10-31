<?php

namespace App\Support\Contacts;

/**
 * Data referensi jenis kontak & tautan bio link. Sumber untuk migrasi/seeder tabel
 * `contact_type_groups` + `contact_types`; aplikasi membaca dari tabel (ContactTypes), bukan dari sini.
 *
 * input:  phone | email | url | handle | text | bank | wallet | image
 * action: call | link | map | copy | image | none   (apa yang terjadi saat pengunjung mengetuk)
 * vcard:  tel | email | url | adr | social | none
 * icon:   nama ikon lucide (frontend memetakan ke komponen; tanpa ikon merek di luar lucide)
 * url:    pola tautan; {v} = isian (handle/URL), {digits} = nomor tanpa simbol
 */
final class ContactCatalog
{
    public const GROUPS = [
        'contact' => 'Kontak utama',
        'messaging' => 'Pesan',
        'social' => 'Sosial media',
        'business' => 'Bisnis',
        'store' => 'Toko online',
        'payment' => 'Pembayaran',
        'other' => 'Lainnya',
    ];

    /** key => [grup, label, input, action, vcard, ikon, pola url, placeholder] */
    public const TYPES = [
        'phone' => ['contact', 'Telepon', 'phone', 'call', 'tel', 'Phone', 'tel:{v}', '0812 3456 7890'],
        'whatsapp' => ['contact', 'WhatsApp', 'phone', 'link', 'tel', 'MessageCircle', 'https://wa.me/{digits}', '0812 3456 7890'],
        'whatsapp_business' => ['contact', 'WhatsApp Business', 'phone', 'link', 'tel', 'MessagesSquare', 'https://wa.me/{digits}', '0812 3456 7890'],
        'email' => ['contact', 'Email', 'email', 'link', 'email', 'Mail', 'mailto:{v}', 'nama@perusahaan.com'],

        'telegram' => ['messaging', 'Telegram', 'handle', 'link', 'social', 'Send', 'https://t.me/{v}', 'username'],
        'line' => ['messaging', 'LINE', 'handle', 'link', 'social', 'MessageCircle', 'https://line.me/ti/p/~{v}', 'line id'],
        'signal' => ['messaging', 'Signal', 'phone', 'link', 'none', 'MessagesSquare', 'https://signal.me/#p/+{digits}', '0812 3456 7890'],
        'discord' => ['messaging', 'Discord', 'handle', 'copy', 'none', 'MessagesSquare', null, 'username'],
        'wechat' => ['messaging', 'WeChat', 'handle', 'copy', 'none', 'MessageCircle', null, 'wechat id'],

        'instagram' => ['social', 'Instagram', 'handle', 'link', 'social', 'Instagram', 'https://instagram.com/{v}', 'username'],
        'linkedin' => ['social', 'LinkedIn', 'handle', 'link', 'social', 'Linkedin', 'https://www.linkedin.com/in/{v}', 'nama-anda'],
        'tiktok' => ['social', 'TikTok', 'handle', 'link', 'social', 'Music2', 'https://www.tiktok.com/@{v}', 'username'],
        'facebook' => ['social', 'Facebook', 'handle', 'link', 'social', 'Facebook', 'https://facebook.com/{v}', 'username'],
        'x' => ['social', 'X (Twitter)', 'handle', 'link', 'social', 'Twitter', 'https://x.com/{v}', 'username'],
        'youtube' => ['social', 'YouTube', 'handle', 'link', 'social', 'Youtube', 'https://youtube.com/@{v}', 'channel'],
        'threads' => ['social', 'Threads', 'handle', 'link', 'social', 'AtSign', 'https://www.threads.net/@{v}', 'username'],
        'pinterest' => ['social', 'Pinterest', 'handle', 'link', 'social', 'Pin', 'https://pinterest.com/{v}', 'username'],
        'github' => ['social', 'GitHub', 'handle', 'link', 'social', 'Github', 'https://github.com/{v}', 'username'],
        'behance' => ['social', 'Behance', 'handle', 'link', 'social', 'Palette', 'https://www.behance.net/{v}', 'username'],
        'dribbble' => ['social', 'Dribbble', 'handle', 'link', 'social', 'Dribbble', 'https://dribbble.com/{v}', 'username'],
        'medium' => ['social', 'Medium', 'handle', 'link', 'social', 'BookOpen', 'https://medium.com/@{v}', 'username'],
        'spotify' => ['social', 'Spotify', 'url', 'link', 'url', 'Headphones', null, 'https://open.spotify.com/…'],
        'podcast' => ['social', 'Podcast', 'url', 'link', 'url', 'Podcast', null, 'https://…'],
        'social' => ['social', 'Sosial media lain', 'url', 'link', 'url', 'AtSign', null, 'https://…'],

        'url' => ['business', 'Website', 'url', 'link', 'url', 'Globe', null, 'https://perusahaan.com'],
        'portfolio' => ['business', 'Portofolio', 'url', 'link', 'url', 'Briefcase', null, 'https://…'],
        'booking' => ['business', 'Jadwalkan meeting', 'url', 'link', 'url', 'CalendarCheck', null, 'https://calendly.com/…'],
        'maps' => ['business', 'Lokasi Google Maps', 'url', 'link', 'url', 'MapPin', null, 'https://maps.app.goo.gl/…'],
        'review' => ['business', 'Ulasan Google', 'url', 'link', 'url', 'Star', null, 'https://g.page/r/…'],
        'file' => ['business', 'Katalog / dokumen', 'url', 'link', 'url', 'FileText', null, 'https://…/katalog.pdf'],
        'address' => ['business', 'Alamat', 'text', 'map', 'adr', 'MapPin', 'https://maps.google.com/?q={v}', 'Jl. Sudirman No. 1, Jakarta'],
        'hours' => ['business', 'Jam operasional', 'text', 'none', 'none', 'Clock', null, 'Senin–Jumat 09.00–17.00'],

        'tokopedia' => ['store', 'Tokopedia', 'handle', 'link', 'url', 'ShoppingBag', 'https://www.tokopedia.com/{v}', 'nama-toko'],
        'shopee' => ['store', 'Shopee', 'handle', 'link', 'url', 'ShoppingBag', 'https://shopee.co.id/{v}', 'nama-toko'],
        'lazada' => ['store', 'Lazada', 'url', 'link', 'url', 'ShoppingBag', null, 'https://www.lazada.co.id/shop/…'],
        'tiktok_shop' => ['store', 'TikTok Shop', 'url', 'link', 'url', 'Store', null, 'https://…'],
        'store' => ['store', 'Toko online lain', 'url', 'link', 'url', 'Store', null, 'https://…'],

        'bank' => ['payment', 'Rekening bank', 'bank', 'copy', 'none', 'Landmark', null, '1234567890'],
        'qris' => ['payment', 'QRIS', 'image', 'image', 'none', 'ScanQrCode', null, null],
        'gopay' => ['payment', 'GoPay', 'wallet', 'copy', 'none', 'Wallet', null, '0812 3456 7890'],
        'ovo' => ['payment', 'OVO', 'wallet', 'copy', 'none', 'Wallet', null, '0812 3456 7890'],
        'dana' => ['payment', 'DANA', 'wallet', 'copy', 'none', 'Wallet', null, '0812 3456 7890'],
        'shopeepay' => ['payment', 'ShopeePay', 'wallet', 'copy', 'none', 'Wallet', null, '0812 3456 7890'],
        'linkaja' => ['payment', 'LinkAja', 'wallet', 'copy', 'none', 'Wallet', null, '0812 3456 7890'],

        'link' => ['other', 'Tautan lain', 'url', 'link', 'url', 'Link', null, 'https://…'],
    ];

    public const BANKS = [
        'BCA', 'Mandiri', 'BNI', 'BRI', 'BSI', 'CIMB Niaga', 'Permata', 'Danamon', 'BTN', 'OCBC', 'Maybank',
        'Bank Jago', 'SeaBank', 'blu by BCA Digital', 'Jenius (SMBC Indonesia)', 'Bank DKI', 'Bank lainnya',
    ];

    /** Baris siap insert untuk migrasi/seeder. */
    public static function groupRows(): array
    {
        $i = 0;

        return array_map(fn ($k, $name) => ['key' => $k, 'name' => $name, 'sort' => $i++], array_keys(self::GROUPS), self::GROUPS);
    }

    public static function typeRows(array $groupIds): array
    {
        $i = 0;
        $rows = [];
        foreach (self::TYPES as $key => [$group, $label, $input, $action, $vcard, $icon, $url, $placeholder]) {
            $rows[] = [
                'key' => $key, 'group_id' => $groupIds[$group], 'label' => $label, 'input' => $input, 'action' => $action,
                'vcard' => $vcard, 'icon' => $icon, 'url_template' => $url, 'placeholder' => $placeholder, 'sort' => $i++, 'is_active' => true,
            ];
        }

        return $rows;
    }
}
