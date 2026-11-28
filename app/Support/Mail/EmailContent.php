<?php

namespace App\Support\Mail;

use Illuminate\Support\Carbon;

/**
 * Isi email untuk setiap jenis notifikasi (Notify::user) — subjek, judul, paragraf, tombol, dan nada warna.
 * Satu tempat agar semua email seragam; tampilan di resources/views/mail/notification(.text).blade.php.
 */
final class EmailContent
{
    /**
     * Default saklar "Email" bila pengguna belum mengaturnya: urusan uang, pesanan, kuota, dan keamanan menyala;
     * aktivitas harian (tap, koneksi baru, pengingat) mati agar kotak masuk tidak penuh.
     */
    public const DEFAULT_ON = [
        'invoice_due' => true, 'invoice_paid' => true, 'plan_changed' => true, 'workspace_expired' => true, 'subscription_past_due' => true,
        'grace_ending' => true, 'subscription_reminder' => true, 'trial_ending' => true, 'order_status' => true, 'order_unpaid' => true, 'tap_quota' => true, 'lost_card_tapped' => true,
        'webhook_disabled' => true, 'domain_unverified' => true, 'card_period' => true, 'card_renewed' => true,
    ];

    /** Selalu dikirim, tidak bisa dimatikan (tidak tampil di daftar preferensi). */
    public const ALWAYS = ['account_deleted', 'password_reset', 'system_alert', 'system_recovered', 'monitor_down', 'monitor_degraded', 'monitor_up'];

    /** `**tebal**` → <strong>, setelah teks di-escape (nama/catatan berasal dari pengguna). */
    public static function html(string $line): string
    {
        return preg_replace('/\*\*(.+?)\*\*/', '<strong style="color:#141210">$1</strong>', e($line));
    }

    public static function plain(string $line): string
    {
        return str_replace('**', '', $line);
    }

    public static function defaultOn(string $type): bool
    {
        return self::DEFAULT_ON[$type] ?? false;
    }

    /**
     * Nada (tone): default | ok | warn | danger.
     *
     * @return array{subject:string, title:string, lines:list<string>, action:?array{label:string,path?:string,url?:string}, tone:string, preheader:string, facts:list<array{0:string,1:string}>, items:list<array>}|null
     */
    public static function for(string $type, array $d): ?array
    {
        $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
        $date = fn ($iso) => $iso ? Carbon::parse($iso)->timezone('Asia/Jakarta')->locale('id')->translatedFormat('j F Y') : '-';
        $num = fn ($n) => number_format((int) $n, 0, ',', '.');
        $invoice = fn () => ['label' => 'Lihat tagihan', 'path' => '/billing/invoices/'.($d['id'] ?? '')];

        $c = match ($type) {
            'invoice_due' => [
                'subject' => "Tagihan perpanjangan {$d['number']}",
                'title' => 'Tagihan perpanjangan paket sudah terbit',
                'lines' => [
                    "Tagihan **{$d['number']}** sebesar **{$rp($d['total'] ?? 0)}** untuk perpanjangan paket Anda sudah terbit.",
                    "Bayar sebelum **{$date($d['due'] ?? null)}** agar paket dan fiturnya tetap berjalan tanpa jeda.",
                ],
                'action' => ['label' => 'Bayar sekarang', 'path' => '/billing/invoices/'.($d['id'] ?? '')],
                'tone' => 'default',
            ],
            'invoice_paid' => [
                'subject' => "Pembayaran {$d['number']} berhasil",
                'title' => 'Pembayaran berhasil',
                'lines' => array_values(array_filter([
                    "Terima kasih! Pembayaran untuk tagihan **{$d['number']}** sudah kami terima.",
                    isset($d['label']) ? "**{$d['label']}** sudah aktif." : (isset($d['plan']) ? "Paket **{$d['plan']}** aktif".(isset($d['until']) ? " sampai **{$date($d['until'])}**." : '.') : null),
                    'Simpan email ini sebagai bukti pembayaran. Invoice lengkap bisa diunduh dari halaman tagihan.',
                ])),
                'action' => $invoice(),
                'tone' => 'ok',
            ],
            'subscription_past_due' => [
                'subject' => 'Paket Anda masuk masa tenggang',
                'title' => 'Masa tenggang dimulai',
                'lines' => [
                    "Periode paket **{$d['plan']}** sudah berakhir dan tagihan perpanjangannya belum dibayar.",
                    "Semua fitur masih bisa dipakai sampai **{$date($d['grace_until'] ?? null)}**. ".(empty($d['company'])
                        ? 'Setelah itu workspace otomatis turun ke paket gratis dan fitur berbayar terkunci.'
                        : 'Setelah itu dashboard workspace terkunci sampai diperpanjang. Kartu karyawan tetap bisa di-tap.'),
                ],
                'action' => isset($d['id']) ? ['label' => 'Bayar tagihan', 'path' => '/billing/invoices/'.$d['id']] : ['label' => 'Buka paket', 'path' => '/billing'],
                'tone' => 'warn',
            ],
            'grace_ending' => [
                'subject' => 'Masa tenggang paket berakhir '.$date($d['grace_until'] ?? null),
                'title' => 'Masa tenggang hampir berakhir',
                'lines' => [
                    empty($d['company'])
                        ? "Paket **{$d['plan']}** akan turun ke paket gratis pada **{$date($d['grace_until'] ?? null)}** karena tagihan perpanjangan belum dibayar."
                        : "Dashboard workspace akan terkunci pada **{$date($d['grace_until'] ?? null)}** karena tagihan perpanjangan belum dibayar.",
                    'Bayar sekarang agar data, kartu, dan fitur berbayar tidak terkunci.',
                ],
                'action' => isset($d['id']) ? ['label' => 'Bayar tagihan', 'path' => '/billing/invoices/'.$d['id']] : ['label' => 'Buka paket', 'path' => '/billing'],
                'tone' => 'danger',
            ],
            'plan_changed' => [
                'subject' => "Paket workspace kini {$d['plan']}",
                'title' => 'Paket workspace berubah',
                'lines' => array_values(array_filter([
                    match ($d['reason'] ?? null) {
                        'expired' => 'Masa tenggang sudah habis dan tagihan perpanjangan tidak dibayar.',
                        'cancelled' => 'Langganan berhenti sesuai permintaan pembatalan Anda.',
                        default => null,
                    },
                    "Workspace Anda sekarang memakai paket **{$d['plan']}**. Data tetap aman; fitur di luar paket ini terkunci sampai Anda berlangganan lagi.",
                ])),
                'action' => ['label' => 'Lihat pilihan paket', 'path' => '/billing'],
                'tone' => 'warn',
            ],
            'subscription_reminder' => [
                'subject' => 'Masa aktif '.($d['workspace'] ?? 'workspace').' berakhir '.$date($d['end'] ?? null),
                'title' => ($d['days'] ?? 0) <= 1 ? 'Masa aktif berakhir besok' : "Masa aktif berakhir {$d['days']} hari lagi",
                'lines' => array_values(array_filter([
                    'Masa aktif paket **'.($d['plan'] ?? '-').'** untuk **'.($d['workspace'] ?? 'workspace Anda').'** berakhir pada **'.$date($d['end'] ?? null).'**.',
                    isset($d['number']) ? "Tagihan perpanjangan **{$d['number']}** sudah terbit." : null,
                    'Perpanjang sebelum tanggal itu agar dashboard dan subdomain tidak terkunci. Kartu karyawan tetap bisa di-tap.',
                ])),
                'action' => isset($d['id']) ? ['label' => 'Bayar tagihan', 'path' => '/billing/invoices/'.$d['id']] : ['label' => 'Perpanjang sekarang', 'path' => '/billing'],
                'tone' => ($d['days'] ?? 0) <= 1 ? 'danger' : (($d['days'] ?? 0) <= 7 ? 'warn' : 'default'),
            ],
            'trial_ending' => [
                'subject' => 'Uji coba '.($d['workspace'] ?? 'workspace').' berakhir besok',
                'title' => 'Uji coba berakhir besok',
                'lines' => [
                    'Masa uji coba paket **'.($d['plan'] ?? '-').'** untuk **'.($d['workspace'] ?? 'workspace Anda').'** berakhir pada **'.$date($d['end'] ?? null).'**.',
                    'Pilih paket dan bayar per 6 bulan agar dashboard dan subdomain tetap terbuka. Kartu karyawan tetap bisa di-tap.',
                ],
                'action' => ['label' => 'Pilih paket', 'path' => '/billing'],
                'tone' => 'warn',
            ],
            'webhook_disabled' => [
                'subject' => 'Webhook dinonaktifkan otomatis',
                'title' => 'Webhook dinonaktifkan otomatis',
                'lines' => [
                    'Pengiriman ke **'.($d['url'] ?? '-').'** gagal **'.($d['failures'] ?? 20).' kali berturut-turut**, jadi endpoint ini kami nonaktifkan agar tidak terus mengirim.',
                    'Periksa server penerima (HTTPS, balasan 2xx, waktu balas < 5 detik), lalu aktifkan lagi dari halaman Webhook. Event selama nonaktif tidak dikirim.',
                ],
                'action' => ['label' => 'Buka Webhook', 'path' => '/webhooks'],
                'tone' => 'danger',
            ],
            'workspace_expired' => [
                'subject' => 'Masa aktif workspace habis',
                'title' => 'Masa aktif workspace habis',
                'lines' => [
                    ($d['reason'] ?? null) === 'trial' ? "Masa uji coba paket **{$d['plan']}** sudah selesai." : "Masa aktif paket **{$d['plan']}** sudah habis.",
                    'Dashboard dan subdomain workspace terkunci sampai diperpanjang. Kartu karyawan tetap bisa di-tap dan datanya aman.',
                ],
                'action' => ['label' => 'Perpanjang sekarang', 'path' => '/billing'],
                'tone' => 'danger',
            ],
            'order_status' => [
                'subject' => "Pesanan {$d['number']}: {$d['label']}",
                'title' => 'Status pesanan diperbarui',
                'lines' => array_values(array_filter([
                    "Pesanan **{$d['number']}** sekarang: **{$d['label']}**.",
                    ! empty($d['tracking']) ? "Nomor resi: **{$d['tracking']}**." : null,
                ])),
                'action' => ['label' => 'Lihat pesanan', 'path' => '/orders/'.($d['id'] ?? '')],
                'tone' => ($d['status'] ?? '') === 'cancelled' ? 'warn' : 'default',
            ],
            'order_unpaid' => [
                'subject' => "Pesanan {$d['number']} belum dibayar",
                'title' => 'Pesanan Anda menunggu pembayaran',
                'lines' => [
                    "Pesanan **{$d['number']}** sebesar **{$rp($d['total'] ?? 0)}** belum dibayar.",
                    "Bila belum dibayar sampai **{$date($d['cancel_at'] ?? null)}**, pesanan dibatalkan otomatis. Anda tetap bisa memesan lagi kapan saja.",
                ],
                'action' => ['label' => 'Bayar pesanan', 'path' => '/orders/'.($d['id'] ?? '')],
                'tone' => 'warn',
            ],
            'card_period' => [
                'subject' => match ($d['stage'] ?? '') {
                    'grace_end' => 'Masa pakai kartu sudah habis',
                    'h1' => 'Besok masa pakai kartu berakhir',
                    default => "Masa pakai kartu berakhir {$d['days']} hari lagi",
                },
                'title' => match ($d['stage'] ?? '') {
                    'grace_end' => 'Kartu nonaktif — perpanjang untuk mengaktifkan lagi',
                    default => 'Perpanjang masa pakai kartu Anda',
                },
                'lines' => [
                    "Kartu **{$d['label']}** berlaku sampai **{$date($d['until'])}**.",
                    ($d['stage'] ?? '') === 'grace_end'
                        ? 'Kartu tidak lagi menampilkan profil. Perpanjang dalam 7 hari untuk tetap aktif tanpa kehilangan data.'
                        : "Perpanjang {$rp($d['renew_price'] ?? 0)} untuk 6 bulan ke depan dari halaman kartu Anda.",
                ],
                'action' => ['label' => 'Perpanjang kartu', 'path' => "/cards/{$d['card']}"],
                'tone' => ($d['stage'] ?? '') === 'grace_end' ? 'danger' : 'warn',
            ],
            'card_renewed' => [
                'subject' => 'Masa pakai kartu diperpanjang',
                'title' => 'Masa pakai kartu Anda sudah diperpanjang',
                'lines' => ["Kartu {$d['label']} berlaku sampai **{$date($d['until'])}**.", 'Kuitansi tersedia di menu Paket & tagihan.'],
                'action' => ['label' => 'Lihat kartu', 'path' => "/cards/{$d['card']}"],
                'tone' => 'ok',
            ],
            'tap_quota' => ($d['level'] ?? 0) >= 100 ? [
                'subject' => 'Kuota tap kartu digital habis',
                'title' => 'Kuota tap habis — profil tidak bisa dibuka',
                'lines' => [
                    "Kartu digital Anda sudah dipakai **{$num($d['used'])} dari {$num($d['quota'])}** tap.",
                    'Sampai kuota ditambah, orang yang men-tap atau membuka tautan kartu digital Anda tidak bisa melihat profil Anda.',
                ],
                'action' => ['label' => 'Tambah kuota tap', 'path' => '/settings'],
                'tone' => 'danger',
            ] : [
                'subject' => 'Kuota tap kartu digital hampir habis',
                'title' => 'Kuota tap hampir habis',
                'lines' => [
                    "Kartu digital Anda sudah dipakai **{$num($d['used'])} dari {$num($d['quota'])}** tap.",
                    'Tambah kuota sebelum habis agar profil Anda tetap bisa dibuka.',
                ],
                'action' => ['label' => 'Tambah kuota tap', 'path' => '/settings'],
                'tone' => 'warn',
            ],
            'domain_unverified' => [
                'subject' => 'Domain kustom Anda tidak lagi terverifikasi',
                'title' => 'Domain kustom perlu diperiksa',
                'lines' => [
                    "Record TXT verifikasi untuk **{$d['domain']}** tidak lagi ditemukan di DNS.",
                    'Domain dinonaktifkan sementara sampai record dipasang kembali dan diverifikasi ulang oleh tim TapCard.',
                ],
                'action' => ['label' => 'Buka pengaturan', 'path' => '/settings'],
                'tone' => 'warn',
            ],
            'lost_card_tapped' => [
                'subject' => 'Kartu Anda yang hilang baru saja di-tap',
                'title' => 'Kartu hilang di-tap',
                'lines' => array_values(array_filter([
                    'Seseorang baru saja men-tap kartu yang Anda laporkan hilang'.(! empty($d['city']) ? " di sekitar **{$d['city']}**." : '.'),
                    'Data pribadi Anda tetap tersembunyi. Penemu melihat pesan bahwa kartu ini dilaporkan hilang.',
                ])),
                'action' => isset($d['card']) ? ['label' => 'Lihat kartu', 'path' => '/cards/'.$d['card']] : ['label' => 'Kartu saya', 'path' => '/cards'],
                'tone' => 'warn',
            ],
            'tap' => [
                'subject' => 'Profil Anda baru saja dibuka',
                'title' => 'Kartu Anda di-tap',
                'lines' => ['Seseorang membuka profil Anda'.(! empty($d['city']) ? " dari **{$d['city']}**." : '.')],
                'action' => ['label' => 'Lihat analitik', 'path' => '/analytics'],
                'tone' => 'default',
            ],
            'lead_assigned' => [
                'subject' => ($d['count'] ?? 1) > 1 ? "{$d['count']} lead ditugaskan ke Anda" : "Lead ditugaskan ke Anda: {$d['name']}",
                'title' => 'Lead baru untuk Anda',
                'lines' => [($d['by'] ?? 'Rekan Anda').' menugaskan '.(($d['count'] ?? 1) > 1 ? "**{$d['count']} lead** (termasuk {$d['name']})" : "**{$d['name']}**").' ke Anda. Segera follow-up selagi hangat.'],
                'action' => ! empty($d['id']) ? ['label' => 'Buka lead', 'path' => '/connections/'.$d['id']] : ['label' => 'Lihat lead saya', 'path' => '/connections'],
                'tone' => 'default',
            ],
            'lead_new' => [
                'subject' => "Koneksi baru: {$d['name']}",
                'title' => 'Koneksi baru',
                'lines' => ["**{$d['name']}**".(! empty($d['company']) ? " ({$d['company']})" : '').' baru saja terhubung dengan Anda.'],
                'action' => ['label' => 'Lihat koneksi', 'path' => '/connections/'.($d['id'] ?? '')],
                'tone' => 'default',
            ],
            'saved_back' => [
                'subject' => "{$d['name']} menyimpan kontak Anda",
                'title' => 'Kontak Anda disimpan',
                'lines' => ["**{$d['name']}** menyimpan Anda sebagai koneksi. Buka Scan untuk menyimpan balik kontaknya."],
                'action' => ['label' => 'Buka Scan', 'path' => '/scan'],
                'tone' => 'default',
            ],
            'reminder_due' => [
                'subject' => "Waktunya follow-up {$d['name']}",
                'title' => 'Pengingat follow-up',
                'lines' => array_values(array_filter([
                    "Waktunya menghubungi **{$d['name']}**".(! empty($d['company']) ? " ({$d['company']})" : '').'.',
                    ! empty($d['note']) ? "Catatan: {$d['note']}" : null,
                ])),
                'action' => ['label' => 'Buka koneksi', 'path' => '/connections/'.($d['id'] ?? '')],
                'tone' => 'default',
            ],
            'import_done' => [
                'subject' => "Impor {$d['filename']} selesai",
                'title' => 'Impor kontak selesai',
                'lines' => ["File **{$d['filename']}**: {$num($d['created'])} kontak baru, {$num($d['merged'])} digabung, {$num($d['failed'])} gagal."],
                'action' => ['label' => 'Lihat koneksi', 'path' => '/connections'],
                'tone' => ($d['failed'] ?? 0) > 0 ? 'warn' : 'ok',
            ],
            'account_deleted' => [
                'subject' => 'Akun TapCard Anda telah dihapus',
                'title' => 'Akun Anda telah dihapus',
                'lines' => [
                    'Sesuai permintaan Anda, akun TapCard ini sudah dihapus. Semua kartu dicabut dan Anda sudah keluar dari semua perangkat.',
                    "Data pribadi (profil, bio link, koneksi) dihapus permanen pada **{$date($d['purge_at'] ?? null)}**. Invoice dan pesanan tetap kami simpan sebagai catatan keuangan, tanpa identitas Anda.",
                    'Mendaftar lagi dengan email ini akan membuat akun baru yang kosong. Bila Anda tidak merasa menghapus akun, segera hubungi tim TapCard.',
                ],
                'action' => null,
                'tone' => 'danger',
            ],
            'password_reset' => [
                'subject' => 'Buat password baru panel TapCard',
                'title' => 'Permintaan reset password',
                'lines' => [
                    'Kami menerima permintaan untuk membuat password baru akun panel Anda.',
                    "Tautan di bawah berlaku **{$d['minutes']} menit** dan hanya bisa dipakai sekali. Setelah password diganti, semua sesi panel yang masih terbuka akan berakhir.",
                    'Bila Anda tidak meminta reset, abaikan email ini — password Anda tidak berubah.',
                ],
                'action' => ['label' => 'Buat password baru', 'url' => $d['url']],
                'tone' => 'default',
            ],
            'system_alert' => [
                'subject' => '[TapCard] Perlu perhatian: '.implode(', ', array_column($d['checks'], 'label')),
                'title' => 'Ada yang perlu diperiksa di server',
                'lines' => [
                    ...array_map(fn ($c) => "**{$c['label']}**: {$c['value']}".(! empty($c['hint']) ? " — {$c['hint']}" : ''), $d['checks']),
                    ($d['reminder'] ?? false) ? 'Pengingat: masalah ini masih berlangsung sejak pemberitahuan sebelumnya.' : 'Anda akan diberi tahu lagi bila belum pulih dalam beberapa jam, dan saat semuanya kembali normal.',
                ],
                'action' => ['label' => 'Buka Sistem di panel', 'url' => $d['url']],
                'tone' => 'danger',
            ],
            'monitor_down' => [
                'subject' => "[TapCard] DOWN: {$d['name']}",
                'title' => "{$d['name']} tidak bisa diakses",
                'lines' => array_values(array_filter([
                    "Monitor **{$d['name']}** gagal dicek beberapa kali berturut-turut.",
                    ! empty($d['reason']) ? "Penyebab: **{$d['reason']}**" : null,
                    "Alamat: {$d['target']}",
                ])),
                'action' => ['label' => 'Buka Uptime di panel', 'url' => $d['url']],
                'tone' => 'danger',
            ],
            'monitor_degraded' => [
                'subject' => "[TapCard] Perlu perhatian: {$d['name']}",
                'title' => "{$d['name']} perlu perhatian",
                'lines' => array_values(array_filter([! empty($d['reason']) ? "**{$d['reason']}**." : null, "Alamat: {$d['target']}"])),
                'action' => ['label' => 'Buka Uptime di panel', 'url' => $d['url']],
                'tone' => 'warn',
            ],
            'monitor_up' => [
                'subject' => "[TapCard] PULIH: {$d['name']}",
                'title' => "{$d['name']} kembali normal",
                'lines' => array_values(array_filter([
                    "Monitor **{$d['name']}** sudah bisa diakses lagi.",
                    ! empty($d['since']) ? 'Gangguan berlangsung sekitar **'.Carbon::parse($d['since'])->locale('id')->diffForHumans(now(), ['syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 2]).'**.' : null,
                ])),
                'action' => ['label' => 'Buka Uptime di panel', 'url' => $d['url']],
                'tone' => 'ok',
            ],
            'system_recovered' => [
                'subject' => '[TapCard] Sistem kembali normal',
                'title' => 'Semua pemeriksaan kembali normal',
                'lines' => ['Masalah yang sebelumnya dilaporkan sudah pulih: **'.implode(', ', $d['labels']).'**.'],
                'action' => ['label' => 'Buka Sistem di panel', 'url' => $d['url']],
                'tone' => 'ok',
            ],
            default => null,
        };

        return $c === null ? null : $c + self::summary($type, $d);
    }

    /** Nama sumber lead untuk ringkasan email. */
    private const LEAD_SOURCES = [
        'exchange_form' => 'Formulir tukar kontak', 'scan_tapcard' => 'Scan kartu TapCard', 'scan_external' => 'Scan kartu nama',
        'manual' => 'Input manual', 'import' => 'Impor CSV', 'event_checkin' => 'Check-in event',
        'saved_from_profile' => 'Disimpan dari profil', 'bio_form' => 'Formulir bio link',
    ];

    /**
     * Preheader (baris pratinjau di kotak masuk), ringkasan poin penting [label, nilai], dan rincian item (bila data
     * membawanya). Hanya dari data yang sudah dikirim ke jenis itu; baris tanpa data dibuang.
     *
     * @return array{preheader:string, facts:list<array{0:string,1:string}>, items:list<array{label:string,qty:int,amount:?string}>}
     */
    private static function summary(string $type, array $d): array
    {
        $rp = fn ($n) => isset($n) ? 'Rp'.number_format((int) $n, 0, ',', '.') : null;
        $date = fn ($iso) => $iso ? Carbon::parse($iso)->timezone('Asia/Jakarta')->locale('id')->translatedFormat('j F Y') : null;
        $time = fn ($iso) => $iso ? Carbon::parse($iso)->timezone('Asia/Jakarta')->locale('id')->translatedFormat('j F Y, H.i').' WIB' : null;
        $num = fn ($n) => isset($n) ? number_format((int) $n, 0, ',', '.') : null;
        $now = $time(now()->toIso8601String());
        // gabung potongan kalimat, lewati yang kosong
        $s = fn (...$parts) => implode(' ', array_filter($parts, fn ($p) => $p !== null && $p !== ''));
        $many = ($d['count'] ?? 1) > 1;

        [$preheader, $facts] = match ($type) {
            'invoice_due' => [
                $s('Tagihan '.($d['number'] ?? ''), isset($d['total']) ? 'sebesar '.$rp($d['total']) : null, isset($d['due']) ? 'jatuh tempo '.$date($d['due']).'.' : null),
                [['Nomor tagihan', $d['number'] ?? null], ['Total', $rp($d['total'] ?? null)], ['Jatuh tempo', $date($d['due'] ?? null)], ['Paket', $d['plan'] ?? null]],
            ],
            'invoice_paid' => [
                'Pembayaran tagihan '.($d['number'] ?? '').' sudah kami terima. Simpan email ini sebagai bukti.',
                [['Nomor tagihan', $d['number'] ?? null], ['Status', 'Lunas'], ['Item', $d['label'] ?? null], ['Paket', $d['plan'] ?? null], ['Aktif sampai', $date($d['until'] ?? null)]],
            ],
            'subscription_past_due', 'grace_ending' => [
                $s('Fitur paket', $d['plan'] ?? null, 'tetap jalan sampai', ($date($d['grace_until'] ?? null) ?? 'akhir masa tenggang').'.', 'Bayar tagihan agar tidak terkunci.'),
                [['Paket', $d['plan'] ?? null], ['Nomor tagihan', $d['number'] ?? null], ['Masa tenggang sampai', $date($d['grace_until'] ?? null)]],
            ],
            'plan_changed' => [
                'Workspace Anda kini memakai paket '.($d['plan'] ?? '-').'. Data tetap aman.',
                [['Paket sekarang', $d['plan'] ?? null], ['Alasan', ['expired' => 'Masa tenggang habis', 'cancelled' => 'Langganan dibatalkan'][$d['reason'] ?? ''] ?? null]],
            ],
            'subscription_reminder' => [
                $s('Masa aktif', $d['plan'] ?? null, 'berakhir', ($date($d['end'] ?? null) ?? '-').'.', isset($d['number']) ? 'Tagihan '.$d['number'].' sudah terbit.' : null),
                [['Workspace', $d['workspace'] ?? null], ['Paket', $d['plan'] ?? null], ['Berakhir pada', $date($d['end'] ?? null)],
                    ['Sisa waktu', isset($d['days']) ? $d['days'].' hari' : null], ['Nomor tagihan', $d['number'] ?? null]],
            ],
            'trial_ending' => [
                $s('Uji coba', $d['plan'] ?? null, 'berakhir', ($date($d['end'] ?? null) ?? 'besok').'.', 'Pilih paket agar dashboard tetap terbuka.'),
                [['Workspace', $d['workspace'] ?? null], ['Paket uji coba', $d['plan'] ?? null], ['Berakhir pada', $date($d['end'] ?? null)]],
            ],
            'webhook_disabled' => [
                'Endpoint '.($d['url'] ?? 'webhook').' gagal '.($d['failures'] ?? 20).' kali berturut-turut dan kini nonaktif.',
                [['Endpoint', $d['url'] ?? null], ['Gagal berturut-turut', ($d['failures'] ?? 20).' kali'], ['Status', 'Nonaktif']],
            ],
            'workspace_expired' => [
                $s(($d['reason'] ?? null) === 'trial' ? 'Uji coba' : 'Masa aktif', 'paket', $d['plan'] ?? null, 'habis. Kartu karyawan tetap bisa di-tap.'),
                [['Paket', $d['plan'] ?? null], ['Jenis', ($d['reason'] ?? null) === 'trial' ? 'Uji coba' : 'Langganan'], ['Dashboard', 'Terkunci sampai diperpanjang']],
            ],
            'order_status' => [
                $s('Pesanan '.($d['number'] ?? '').': '.($d['label'] ?? '').'.', ! empty($d['tracking']) ? 'Resi '.$d['tracking'].'.' : null),
                [['Nomor pesanan', $d['number'] ?? null], ['Status', $d['label'] ?? null], ['Nomor resi', $d['tracking'] ?? null],
                    ['Estimasi', $date($d['estimate'] ?? null)], ['Total', $rp($d['total'] ?? null)]],
            ],
            'order_unpaid' => [
                $s('Pesanan '.($d['number'] ?? ''), isset($d['total']) ? 'sebesar '.$rp($d['total']) : null, 'menunggu pembayaran', isset($d['cancel_at']) ? 'sampai '.$date($d['cancel_at']).'.' : null),
                [['Nomor pesanan', $d['number'] ?? null], ['Total', $rp($d['total'] ?? null)], ['Status', 'Menunggu pembayaran'], ['Batal otomatis', $date($d['cancel_at'] ?? null)]],
            ],
            'card_period' => [
                $s('Kartu', $d['label'] ?? null, 'berlaku sampai', ($date($d['until'] ?? null) ?? '-').'.', isset($d['renew_price']) ? 'Perpanjang '.$rp($d['renew_price']).' untuk 6 bulan.' : null),
                [['Kartu', $d['label'] ?? null], ['Berlaku sampai', $date($d['until'] ?? null)],
                    ['Status', ($d['stage'] ?? '') === 'grace_end' ? 'Nonaktif' : (isset($d['days']) ? 'Berakhir '.$d['days'].' hari lagi' : null)],
                    ['Biaya perpanjangan', isset($d['renew_price']) ? $rp($d['renew_price']).' / 6 bulan' : null]],
            ],
            'card_renewed' => [
                $s('Kartu', $d['label'] ?? null, 'kini berlaku sampai', ($date($d['until'] ?? null) ?? '-').'.'),
                [['Kartu', $d['label'] ?? null], ['Berlaku sampai', $date($d['until'] ?? null)], ['Status', 'Aktif']],
            ],
            'tap_quota' => [
                'Terpakai '.$num($d['used'] ?? 0).' dari '.$num($d['quota'] ?? 0).' tap kartu digital.',
                [['Terpakai', isset($d['used'], $d['quota']) ? $num($d['used']).' / '.$num($d['quota']).' tap' : null],
                    ['Persentase', isset($d['level']) ? ((int) $d['level']).'%' : null],
                    ['Sisa', isset($d['used'], $d['quota']) ? $num(max(0, $d['quota'] - $d['used'])).' tap' : null]],
            ],
            'domain_unverified' => [
                'Record TXT untuk '.($d['domain'] ?? 'domain Anda').' tidak ditemukan; domain dinonaktifkan sementara.',
                [['Domain', $d['domain'] ?? null], ['Status', 'Nonaktif sementara'], ['Diperiksa', $now]],
            ],
            'lost_card_tapped' => [
                $s('Kartu yang dilaporkan hilang di-tap', ! empty($d['city']) ? 'di sekitar '.$d['city'] : null, 'pada', ($time($d['at'] ?? null) ?? $now).'.'),
                [['Kartu', $d['label'] ?? null], ['Lokasi', $d['city'] ?? null], ['Waktu', $time($d['at'] ?? null)]],
            ],
            'tap' => [
                $s('Profil Anda dibuka', ! empty($d['city']) ? 'dari '.$d['city'] : null, 'pada', ($time($d['at'] ?? null) ?? $now).'.'),
                [['Kartu', $d['label'] ?? null], ['Lokasi', $d['city'] ?? null], ['Waktu', $time($d['at'] ?? null)],
                    ['Jumlah tap', $many ? $num($d['count']).' kali' : null]],
            ],
            'lead_assigned' => [
                ($d['by'] ?? 'Rekan Anda').' menugaskan '.($many ? $d['count'].' lead' : ($d['name'] ?? 'lead')).' ke Anda.',
                [['Lead', $d['name'] ?? null], ['Jumlah', $many ? $d['count'].' lead' : null], ['Ditugaskan oleh', $d['by'] ?? null], ['Penanggung jawab', 'Anda']],
            ],
            'lead_new' => [
                $s($d['name'] ?? 'Kontak baru', ! empty($d['company']) ? '('.$d['company'].')' : null, 'baru saja terhubung dengan Anda.'),
                [['Nama', $d['name'] ?? null], ['Perusahaan', $d['company'] ?? null], ['Sumber', self::LEAD_SOURCES[$d['source'] ?? ''] ?? null]],
            ],
            'saved_back' => [
                ($d['name'] ?? 'Seseorang').' menyimpan Anda sebagai koneksi.',
                [['Nama', $d['name'] ?? null]],
            ],
            'reminder_due' => [
                $s('Waktunya follow-up '.($d['name'] ?? ''), ! empty($d['company']) ? '('.$d['company'].')' : null),
                [['Nama', $d['name'] ?? null], ['Perusahaan', $d['company'] ?? null], ['Catatan', $d['note'] ?? null]],
            ],
            'import_done' => [
                $num($d['created'] ?? 0).' kontak baru, '.$num($d['merged'] ?? 0).' digabung, '.$num($d['failed'] ?? 0).' gagal.',
                [['File', $d['filename'] ?? null], ['Kontak baru', $num($d['created'] ?? null)], ['Digabung', $num($d['merged'] ?? null)], ['Gagal', $num($d['failed'] ?? null)]],
            ],
            'account_deleted' => [
                $s('Akun TapCard Anda sudah dihapus.', isset($d['purge_at']) ? 'Data pribadi dihapus permanen '.$date($d['purge_at']).'.' : null),
                [['Status akun', 'Dihapus'], ['Hapus permanen', $date($d['purge_at'] ?? null)]],
            ],
            'password_reset' => [
                'Tautan reset password berlaku '.($d['minutes'] ?? 60).' menit dan hanya sekali pakai.',
                [['Berlaku', isset($d['minutes']) ? $d['minutes'].' menit' : null], ['Pemakaian', 'Sekali pakai'], ['Diminta', $now]],
            ],
            'system_alert' => [
                'Perlu diperiksa: '.implode(', ', array_column($d['checks'], 'label')).'.',
                [...array_map(fn ($c) => [(string) $c['label'], (string) $c['value']], $d['checks']), ['Waktu', $now]],
            ],
            'monitor_down', 'monitor_degraded' => [
                $s($d['name'] ?? 'Monitor', $type === 'monitor_down' ? 'tidak bisa diakses' : 'perlu perhatian', ! empty($d['reason']) ? '— '.$d['reason'] : null),
                [['Layanan', $d['name'] ?? null], ['Status', $type === 'monitor_down' ? 'Down' : 'Terganggu'], ['Penyebab', $d['reason'] ?? null],
                    ['Alamat', $d['target'] ?? null], ['Waktu', $now]],
            ],
            'monitor_up' => [
                ($d['name'] ?? 'Monitor').' kembali normal.',
                [['Layanan', $d['name'] ?? null], ['Status', 'Normal'], ['Gangguan sejak', $time($d['since'] ?? null)], ['Pulih', $now]],
            ],
            'system_recovered' => [
                'Sudah pulih: '.implode(', ', $d['labels']).'.',
                [['Pulih', implode(', ', $d['labels'])], ['Status', 'Normal'], ['Waktu', $now]],
            ],
            default => ['', []],
        };

        // rincian barang hanya bila payload pesanan membawanya (label, qty, amount) — tidak pernah dikarang
        $items = [];
        foreach (str_starts_with($type, 'order_') ? (array) ($d['items'] ?? []) : [] as $i) {
            if (is_array($i) && isset($i['label'])) {
                $items[] = ['label' => (string) $i['label'], 'qty' => (int) ($i['qty'] ?? 1), 'amount' => isset($i['amount']) ? $rp($i['amount']) : null];
            }
        }

        return [
            'preheader' => $preheader,
            'facts' => array_values(array_filter($facts, fn ($f) => $f[1] !== null && $f[1] !== '')),
            'items' => $items,
        ];
    }

    /** Kalimat "Email ini dikirim karena …" di footer, menurut kelompok jenisnya. */
    public static function reason(string $type): string
    {
        return match (true) {
            str_starts_with($type, 'system_') || str_starts_with($type, 'monitor_') => 'Email ini dikirim karena alamat Anda terdaftar sebagai penerima peringatan sistem TapCard. Peringatan ini selalu dikirim.',
            in_array($type, self::ALWAYS, true) => 'Email ini dikirim karena berkaitan dengan keamanan akun Anda, jadi selalu dikirim.',
            self::defaultOn($type) => 'Email ini dikirim karena berkaitan dengan tagihan, pesanan, atau layanan di akun TapCard Anda.',
            default => 'Email ini dikirim karena notifikasi email untuk aktivitas ini aktif di akun Anda.',
        };
    }
}
