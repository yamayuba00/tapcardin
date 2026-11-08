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
        'invoice_due' => true, 'invoice_paid' => true, 'plan_changed' => true, 'subscription_past_due' => true,
        'grace_ending' => true, 'order_status' => true, 'tap_quota' => true, 'lost_card_tapped' => true,
    ];

    /** Selalu dikirim, tidak bisa dimatikan (tidak tampil di daftar preferensi). */
    public const ALWAYS = ['account_deleted'];

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
     * @return array{subject:string, title:string, lines:list<string>, action:?array{label:string,path:string}, tone:string}|null
     *                                                                                                                            tone: default | ok | warn | danger
     */
    public static function for(string $type, array $d): ?array
    {
        $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
        $date = fn ($iso) => $iso ? Carbon::parse($iso)->timezone('Asia/Jakarta')->locale('id')->translatedFormat('j F Y') : '-';
        $num = fn ($n) => number_format((int) $n, 0, ',', '.');
        $invoice = fn () => ['label' => 'Lihat tagihan', 'path' => '/billing/invoices/'.($d['id'] ?? '')];

        return match ($type) {
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
                    "Semua fitur masih bisa dipakai sampai **{$date($d['grace_until'] ?? null)}**. Setelah itu workspace otomatis turun ke paket gratis dan fitur berbayar terkunci.",
                ],
                'action' => isset($d['id']) ? ['label' => 'Bayar tagihan', 'path' => '/billing/invoices/'.$d['id']] : ['label' => 'Buka paket', 'path' => '/billing'],
                'tone' => 'warn',
            ],
            'grace_ending' => [
                'subject' => 'Masa tenggang paket berakhir '.$date($d['grace_until'] ?? null),
                'title' => 'Masa tenggang hampir berakhir',
                'lines' => [
                    "Paket **{$d['plan']}** akan turun ke paket gratis pada **{$date($d['grace_until'] ?? null)}** karena tagihan perpanjangan belum dibayar.",
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
            default => null,
        };
    }
}
