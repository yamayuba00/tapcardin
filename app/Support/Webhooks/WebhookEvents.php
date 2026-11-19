<?php

namespace App\Support\Webhooks;

use App\Models\Tenant;
use App\Models\WebhookEndpoint;

/**
 * Katalog event webhook = dokumentasi di halaman /webhooks. Contoh `data` harus berbentuk persis sama dengan
 * payload asli (dicek tes WebhookTest) agar dokumentasi tidak pernah basi.
 */
final class WebhookEvents
{
    private const LEAD = [
        'id' => '01JD0LEAD00000000000000000', 'name' => 'Sari Wulandari', 'email' => 'sari@contoh.com', 'phone' => '+6281234567890',
        'company' => 'PT Contoh Abadi', 'job_title' => 'Marketing Manager', 'source' => 'exchange_form',
        'stage' => ['id' => '01JD0STAGE0000000000000000', 'name' => 'Baru', 'kind' => 'open'],
        'tags' => ['expo-2026'], 'value_amount' => null, 'note' => 'Tertarik paket tim',
        'assignee' => ['id' => '01JD0USER00000000000000000', 'name' => 'Budi Santoso', 'email' => 'budi@majujaya.id'],
        'card' => ['token' => 'aB3dE5fG7h', 'short_code' => 'TC-7K2M-9QXP', 'label' => 'Kartu Budi'],
        'created_at' => '2026-11-20T09:15:00+07:00', 'updated_at' => '2026-11-20T09:15:00+07:00',
    ];

    private const CARD = [
        'token' => 'aB3dE5fG7h', 'short_code' => 'TC-7K2M-9QXP', 'label' => 'Kartu Budi', 'form' => 'physical', 'status' => 'active',
        'holder' => ['id' => '01JD0USER00000000000000000', 'name' => 'Budi Santoso', 'email' => 'budi@majujaya.id'],
        'assigned_to' => null, 'activated_at' => '2026-11-20T08:00:00+07:00',
    ];

    private const MEMBER = [
        'user' => ['id' => '01JD0USER00000000000000000', 'name' => 'Budi Santoso', 'email' => 'budi@majujaya.id'],
        'role' => 'member', 'status' => 'active',
    ];

    /** @return array<string, array{group:string, label:string, description:string, sample:array, volume?:bool}> */
    public static function all(): array
    {
        return [
            'lead.created' => ['group' => 'Lead', 'label' => 'Lead baru', 'description' => 'Koneksi baru dari Tukar Kontak, form bio, scan, atau input manual. Impor CSV tidak mengirim event per baris.',
                'sample' => ['lead' => self::LEAD]],
            'lead.updated' => ['group' => 'Lead', 'label' => 'Lead diperbarui', 'description' => 'Data lead diubah (nama, kontak, perusahaan, tag, nilai, catatan).',
                'sample' => ['lead' => self::LEAD, 'changes' => ['company', 'tags']]],
            'lead.stage_changed' => ['group' => 'Lead', 'label' => 'Tahap lead berubah', 'description' => 'Lead dipindah ke tahap pipeline lain (termasuk menang / kalah).',
                'sample' => ['lead' => self::LEAD, 'from_stage' => ['id' => '01JD0STAGE0000000000000000', 'name' => 'Baru', 'kind' => 'open'],
                    'to_stage' => ['id' => '01JD0STAGE0000000000000001', 'name' => 'Menang', 'kind' => 'won'], 'lost_reason' => null]],
            'lead.assigned' => ['group' => 'Lead', 'label' => 'Lead ditugaskan', 'description' => 'Penanggung jawab lead diganti (ditugaskan, dialihkan, atau dilepas — `assignee` null). Satu event per lead, juga untuk penugasan massal.',
                'sample' => ['lead' => self::LEAD, 'assignee' => self::LEAD['assignee'],
                    'previous_assignee' => ['id' => '01JD0USER00000000000000001', 'name' => 'Rina Saputra', 'email' => 'rina@majujaya.id'],
                    'assigned_by' => ['id' => '01JD0USER00000000000000002', 'name' => 'Dewi Manager', 'email' => 'dewi@majujaya.id']]],
            'contact.exchanged' => ['group' => 'Lead', 'label' => 'Tukar Kontak', 'description' => 'Pengunjung mengisi form Tukar Kontak di profil kartu. `duplicate` = kontak sudah ada dan lead lama diperbarui.',
                'sample' => ['lead' => self::LEAD, 'card' => ['token' => 'aB3dE5fG7h', 'short_code' => 'TC-7K2M-9QXP', 'label' => 'Kartu Budi'], 'duplicate' => false]],
            'card.activated' => ['group' => 'Kartu', 'label' => 'Kartu diaktifkan', 'description' => 'Kartu fisik diaktifkan oleh karyawan atau petugas lapangan.',
                'sample' => ['card' => self::CARD]],
            'card.status_changed' => ['group' => 'Kartu', 'label' => 'Status kartu berubah', 'description' => 'Kartu dijeda, diaktifkan lagi, dilaporkan hilang, dicabut, atau dikembalikan ke stok.',
                'sample' => ['card' => self::CARD, 'from' => 'active', 'to' => 'paused', 'reason' => 'Cuti']],
            'card.assigned' => ['group' => 'Kartu', 'label' => 'Kartu ditugaskan', 'description' => 'Kartu dari stok perusahaan ditugaskan ke karyawan.',
                'sample' => ['card' => ['assigned_to' => self::MEMBER['user'], 'status' => 'unclaimed', 'holder' => null, 'activated_at' => null] + self::CARD]],
            'card.unassigned' => ['group' => 'Kartu', 'label' => 'Kartu ditarik', 'description' => 'Kartu ditarik dari karyawan dan kembali ke stok perusahaan.',
                'sample' => ['card' => ['status' => 'unclaimed', 'holder' => null, 'assigned_to' => null] + self::CARD]],
            'tap.recorded' => ['group' => 'Kartu', 'label' => 'Kartu di-tap / QR dipindai', 'volume' => true,
                'description' => 'Setiap tap NFC atau scan QR. Volumenya bisa sangat tinggi — aktifkan hanya bila sistem Anda siap.',
                'sample' => ['card' => ['token' => 'aB3dE5fG7h', 'short_code' => 'TC-7K2M-9QXP', 'label' => 'Kartu Budi'], 'holder' => self::MEMBER['user'],
                    'source' => 'nfc', 'city' => 'Jakarta', 'country' => 'ID', 'device' => 'mobile', 'browser' => 'Chrome', 'at' => '2026-11-20T09:14:58+07:00']],
            'event.checkin' => ['group' => 'Event', 'label' => 'Peserta check-in', 'description' => 'Panitia memindai kartu peserta di event. Peserta dari workspace lain dicatat sebagai tamu: hanya nama publik, `id`, `email`, dan `card` null.',
                'sample' => ['event' => ['id' => '01JD0EVENT0000000000000000', 'name' => 'Expo UMKM 2026'],
                    'attendee' => self::MEMBER['user'] + ['guest' => false], 'card' => ['token' => 'aB3dE5fG7h', 'short_code' => 'TC-7K2M-9QXP', 'label' => 'Kartu Budi'],
                    'method' => 'nfc', 'checked_in_by' => ['id' => '01JD0USER00000000000000002', 'name' => 'Dewi Panitia', 'email' => 'dewi@majujaya.id'],
                    'at' => '2026-11-20T09:20:00+07:00']],
            'member.added' => ['group' => 'Karyawan', 'label' => 'Karyawan ditambahkan', 'description' => 'Akun terdaftar ditambahkan sebagai karyawan.',
                'sample' => self::MEMBER],
            'member.role_changed' => ['group' => 'Karyawan', 'label' => 'Peran karyawan berubah', 'description' => 'Peran karyawan diubah.',
                'sample' => self::MEMBER + ['from_role' => 'member']],
            'member.deactivated' => ['group' => 'Karyawan', 'label' => 'Karyawan dinonaktifkan', 'description' => 'Karyawan keluar: akses dicabut dan kartunya kembali ke stok.',
                'sample' => ['status' => 'removed'] + self::MEMBER],
            'member.reactivated' => ['group' => 'Karyawan', 'label' => 'Karyawan diaktifkan kembali', 'description' => 'Karyawan yang dinonaktifkan diberi akses lagi.',
                'sample' => self::MEMBER],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** Katalog lengkap untuk dokumentasi (dashboard /webhooks & wbh.{base}): event + contoh amplop + kebijakan kirim ulang. */
    public static function catalog(Tenant $workspace): array
    {
        $sample = fn (string $type, array $data) => WebhookPayload::envelope('evt_01JD0EVENT0000000000000000', $type, $workspace, $data, '2026-11-20T09:15:00+07:00');

        return [
            'events' => collect(self::all())->map(fn ($e, $key) => [
                'key' => $key, 'group' => $e['group'], 'label' => $e['label'], 'description' => $e['description'],
                'volume' => $e['volume'] ?? false, 'sample' => $sample($key, $e['sample']),
            ])->values()->all(),
            'ping' => $sample('ping', self::pingSample()),
            'retry' => Webhooks::BACKOFF,
            'disable_after' => WebhookEndpoint::DISABLE_AFTER,
        ];
    }

    public static function pingSample(): array
    {
        return ['message' => 'Uji kirim dari TapCard. Endpoint Anda menerima webhook dengan benar.'];
    }
}
