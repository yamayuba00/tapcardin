<?php

namespace App\Authz;

/**
 * Katalog izin (PRD §5.5 + BSD §4.2.1) dan izin bawaan peran sistem (matriks PRD §5.2–§5.3).
 * Satu-satunya sumber untuk seeder dan test matriks izin.
 */
final class Catalog
{
    /** key => [area, label, plan_feature?] */
    public const TENANT = [
        'cards.manage.own' => ['cards', 'Kelola kartu & profil sendiri'],
        'cards.view.team' => ['cards', 'Lihat kartu anggota tim'],
        'cards.view.all' => ['cards', 'Lihat semua kartu anggota'],
        'cards.assign.team' => ['cards', 'Tugaskan & reset kartu tim'],
        'cards.assign.all' => ['cards', 'Tugaskan & reset semua kartu'],
        'cards.status.team' => ['cards', 'Jeda kartu tim'],
        'cards.status.all' => ['cards', 'Ubah status semua kartu'],
        'profiles.edit.own' => ['profiles', 'Ubah profil sendiri'],
        'profiles.edit.all' => ['profiles', 'Ubah semua profil'],
        'template.manage' => ['profiles', 'Template brand & field terkunci'],
        'members.invite' => ['members', 'Undang anggota'],
        'members.remove' => ['members', 'Keluarkan anggota'],
        'teams.manage' => ['members', 'Kelola tim', 'teams'],
        'roles.manage' => ['members', 'Kelola peran'],
        'leads.view.own' => ['leads', 'Lihat lead sendiri'],
        'leads.view.team' => ['leads', 'Lihat lead tim'],
        'leads.view.event' => ['leads', 'Lihat lead event'],
        'leads.view.all' => ['leads', 'Lihat semua lead'],
        'leads.edit.own' => ['leads', 'Ubah lead sendiri'],
        'leads.edit.team' => ['leads', 'Ubah lead tim'],
        'leads.edit.event' => ['leads', 'Ubah lead event'],
        'leads.edit.all' => ['leads', 'Ubah semua lead'],
        'leads.assign.team' => ['leads', 'Alihkan lead tim'],
        'leads.assign.event' => ['leads', 'Alihkan lead event'],
        'leads.assign.all' => ['leads', 'Alihkan semua lead'],
        'leads.delete' => ['leads', 'Hapus lead'],
        'leads.export.own' => ['leads', 'Ekspor lead sendiri'],
        'leads.export.team' => ['leads', 'Ekspor lead tim'],
        'leads.export.event' => ['leads', 'Ekspor lead event'],
        'leads.export.all' => ['leads', 'Ekspor semua lead'],
        'pipeline.manage' => ['leads', 'Kelola pipeline & tahap'],
        'fields.manage' => ['leads', 'Kelola custom field', 'lead_fields'],
        'events.manage.assigned' => ['events', 'Kelola event yang ditugaskan'],
        'events.manage.team' => ['events', 'Kelola event tim'],
        'events.manage.all' => ['events', 'Kelola semua event'],
        'events.checkin' => ['events', 'Check-in peserta', 'events_checkin'],
        'analytics.view.own' => ['analytics', 'Analitik sendiri'],
        'analytics.view.team' => ['analytics', 'Analitik tim'],
        'analytics.view.event' => ['analytics', 'Analitik event'],
        'analytics.view.all' => ['analytics', 'Analitik seluruh workspace'],
        'orders.create' => ['billing', 'Pesan kartu'],
        'billing.manage' => ['billing', 'Kelola paket, invoice, metode bayar'],
        'billing.view' => ['billing', 'Lihat tagihan'],
        'workspace.settings' => ['workspace', 'Pengaturan workspace'],
        'workspace.domain' => ['workspace', 'Ganti subdomain', 'subdomain'],
        'workspace.delete' => ['workspace', 'Transfer & hapus workspace'],
        'integrations.manage' => ['workspace', 'Webhook & integrasi', 'webhooks'],
        'audit.view' => ['workspace', 'Audit log workspace'],
    ];

    public const PLATFORM = [
        'platform.dashboard.business' => ['dashboard', 'Dashboard bisnis'],
        'platform.dashboard.ops' => ['dashboard', 'Dashboard operasional'],
        'platform.tenants.view' => ['tenants', 'Lihat workspace'],
        'platform.tenants.manage' => ['tenants', 'Ubah paket & batas workspace'],
        'platform.tenants.suspend' => ['tenants', 'Suspend / aktifkan workspace'],
        'platform.users.view' => ['users', 'Lihat pengguna'],
        'platform.users.manage' => ['users', 'Blokir & kelola pengguna'],
        'platform.impersonate' => ['users', 'Impersonation'],
        'platform.cards.view' => ['cards', 'Cari kartu'],
        'platform.cards.manage' => ['cards', 'Ubah status kartu'],
        'platform.orders.view' => ['orders', 'Lihat pesanan'],
        'platform.orders.review' => ['orders', 'Review desain'],
        'platform.orders.manage' => ['orders', 'Kelola pesanan'],
        'platform.production.manage' => ['production', 'Batch, encode, QC, kirim'],
        'platform.production.keys' => ['production', 'Kunci SDM kartu'],
        'platform.finance.view' => ['finance', 'Lihat pembayaran'],
        // Refund: pengajuan, keputusan, dan pencatatan transfer dipisah agar tidak berada di satu tangan.
        'platform.finance.refund' => ['finance', 'Ajukan refund'],
        'platform.finance.refund.approve' => ['finance', 'Setujui / tolak refund'],
        'platform.finance.refund.large' => ['finance', 'Setujui refund nominal besar'],
        'platform.finance.refund.payout' => ['finance', 'Catat transfer refund'],
        // Tandai lunas manual, batalkan invoice, beri paket: diajukan di sini, DIPUTUSKAN orang lain (finance.approve).
        'platform.finance.invoices' => ['finance', 'Ajukan: lunas manual, batal invoice, beri paket'],
        'platform.finance.approve' => ['finance', 'Setujui / tolak permintaan keuangan'],
        'platform.finance.reviews' => ['finance', 'Tinjau pembayaran bermasalah'],
        'platform.catalog.manage' => ['catalog', 'Katalog, harga, ongkir'],
        'platform.vouchers.manage' => ['catalog', 'Voucher'],
        'platform.plans.manage' => ['catalog', 'Paket & batas fitur'],
        'platform.templates.manage' => ['content', 'Template kartu global & tema'],
        'platform.comms.manage' => ['content', 'Template pesan & pengumuman'],
        'platform.moderation.manage' => ['content', 'Moderasi laporan profil'],
        'platform.staff.manage' => ['system', 'Kelola staf & peran platform'],
        'platform.system.manage' => ['system', 'Feature flag, maintenance, pengaturan'],
        'platform.audit.view' => ['system', 'Audit log platform'],
        'platform.reports.export' => ['system', 'Ekspor laporan'],
    ];

    private const OWN_BASICS = ['cards.manage.own', 'profiles.edit.own'];

    /** @return array<string, array{name: string, description: string, scope: string, permissions: list<string>}> */
    public static function systemRoles(): array
    {
        $allTenant = array_keys(self::TENANT);
        $allPlatform = array_keys(self::PLATFORM);
        $p = fn (string ...$keys) => array_map(fn ($k) => "platform.$k", $keys);

        return [
            // Lapis 1 · platform (PRD §5.2)
            'platform_owner' => self::role('Pemilik Platform', 'Akses penuh ke seluruh platform', 'platform', $allPlatform),
            'platform_admin' => self::role('Admin Platform', 'Semua operasional kecuali keuangan sensitif, paket, staf, sistem', 'platform', $p(
                'dashboard.business', 'dashboard.ops', 'tenants.view', 'tenants.manage', 'tenants.suspend',
                'users.view', 'users.manage', 'impersonate', 'cards.view', 'cards.manage',
                'orders.view', 'orders.review', 'orders.manage', 'production.manage',
                'catalog.manage', 'vouchers.manage', 'templates.manage', 'comms.manage', 'moderation.manage',
                'audit.view', 'reports.export',
            )),
            'finance' => self::role('Keuangan', 'Pembayaran, refund, invoice, laporan pendapatan', 'platform', $p(
                'dashboard.business', 'dashboard.ops', 'tenants.view', 'users.view', 'orders.view',
                'finance.view', 'finance.refund', 'finance.refund.approve', 'finance.refund.payout', 'finance.invoices',
                'finance.approve', 'finance.reviews', 'reports.export',
            )),
            'support' => self::role('Support', 'Bantu pengguna, kartu, dan pesanan. Mengajukan refund (tidak memutuskan)', 'platform', $p(
                'dashboard.ops', 'tenants.view', 'users.view', 'impersonate', 'cards.view', 'cards.manage',
                'orders.view', 'moderation.manage', 'finance.refund',
            )),
            'production' => self::role('Produksi', 'Batch, cetak, encode, QC, pengiriman', 'platform', $p(
                'dashboard.ops', 'cards.view', 'cards.manage', 'orders.view', 'production.manage', 'production.keys',
            )),
            'design_reviewer' => self::role('Reviewer Desain', 'Review desain pesanan & template global', 'platform', $p(
                'dashboard.ops', 'orders.view', 'orders.review', 'templates.manage',
            )),

            // Workspace Personal (PRD §5.4) — semua izin atas datanya sendiri
            'personal_owner' => self::role('Pemilik', 'Pemilik workspace Personal', 'tenant', [
                ...self::OWN_BASICS, 'cards.view.all', 'cards.status.all', 'profiles.edit.all',
                'leads.view.all', 'leads.edit.all', 'leads.delete', 'leads.export.all', 'pipeline.manage',
                'events.manage.all', 'analytics.view.all', 'orders.create', 'billing.manage', 'billing.view',
                'workspace.settings', 'workspace.delete', 'audit.view',
            ]),

            // Lapis 2 · workspace perusahaan (PRD §5.3)
            'owner' => self::role('Owner', 'Semua akses, termasuk subdomain, paket, hapus workspace', 'tenant', $allTenant),
            'admin' => self::role('Admin', 'Kelola anggota, tim, kartu, template, lead, pengaturan', 'tenant',
                array_values(array_diff($allTenant, ['workspace.domain', 'workspace.delete', 'billing.manage']))),
            'billing' => self::role('Billing', 'Pesan kartu, paket, invoice. Tidak melihat lead', 'tenant', [
                ...self::OWN_BASICS, 'orders.create', 'billing.manage', 'billing.view',
            ]),
            'manager' => self::role('Manager', 'Lead, kartu, dan analitik timnya', 'tenant', [
                ...self::OWN_BASICS, 'cards.view.team', 'cards.status.team',
                'leads.view.team', 'leads.edit.team', 'leads.assign.team', 'leads.export.team',
                'events.manage.team', 'events.checkin', 'analytics.view.team',
            ]),
            'member' => self::role('Anggota', 'Kartu, profil, dan lead sendiri', 'tenant', [
                ...self::OWN_BASICS, 'leads.view.own', 'leads.edit.own', 'leads.export.own', 'analytics.view.own',
            ]),
            'event_staff' => self::role('Panitia Event', 'Event yang ditugaskan & lead-nya', 'tenant', [
                ...self::OWN_BASICS, 'leads.view.event', 'leads.edit.event', 'leads.assign.event', 'leads.export.event',
                'events.manage.assigned', 'events.checkin', 'analytics.view.event',
            ]),
            'viewer' => self::role('Viewer', 'Baca saja: analitik & lead seluruh workspace', 'tenant', [
                'cards.view.all', 'leads.view.all', 'leads.export.all', 'analytics.view.all',
            ]),
        ];
    }

    private static function role(string $name, string $description, string $scope, array $permissions): array
    {
        return compact('name', 'description', 'scope', 'permissions');
    }
}
