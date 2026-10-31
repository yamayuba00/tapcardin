<?php

namespace Tests\Unit;

use App\Authz\Catalog;
use App\Enums\BatchStatus;
use App\Enums\CardStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EnumTest extends TestCase
{
    #[DataProvider('cardTransitions')]
    public function test_card_state_machine_follows_afd(CardStatus $from, CardStatus $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canTransitionTo($to), "{$from->value} → {$to->value}");
    }

    public static function cardTransitions(): array
    {
        $s = fn ($v) => CardStatus::from($v);

        return [
            'diproduksi → dikirim' => [$s('manufactured'), $s('unclaimed'), true],
            'gagal QC' => [$s('manufactured'), $s('revoked'), true],
            'aktivasi' => [$s('unclaimed'), $s('active'), true],
            'jeda' => [$s('active'), $s('paused'), true],
            'lanjutkan' => [$s('paused'), $s('active'), true],
            'hilang dari aktif' => [$s('active'), $s('lost'), true],
            'hilang dari jeda' => [$s('paused'), $s('lost'), true],
            'ditemukan' => [$s('lost'), $s('active'), true],
            'reset org' => [$s('active'), $s('unclaimed'), true],
            'pengganti' => [$s('lost'), $s('revoked'), true],
            'tidak bisa lompat aktif' => [$s('manufactured'), $s('active'), false],
            'revoked final' => [$s('revoked'), $s('active'), false],
            'aktif tidak langsung revoked' => [$s('active'), $s('revoked'), false],
        ];
    }

    public function test_batch_cancel_only_before_print(): void
    {
        $this->assertTrue(BatchStatus::Draft->canTransitionTo(BatchStatus::Cancelled));
        $this->assertTrue(BatchStatus::TokensGenerated->canTransitionTo(BatchStatus::Cancelled));
        $this->assertFalse(BatchStatus::Printed->canTransitionTo(BatchStatus::Cancelled));
    }

    public function test_role_permissions_only_reference_catalog(): void
    {
        $known = array_keys(Catalog::TENANT + Catalog::PLATFORM);
        foreach (Catalog::systemRoles() as $key => $role) {
            $this->assertSame([], array_diff($role['permissions'], $known), "peran $key memakai izin di luar katalog");
            $prefix = $role['scope'] === 'platform' ? 'platform.' : '';
            foreach ($role['permissions'] as $p) {
                $this->assertSame($role['scope'] === 'platform', str_starts_with($p, 'platform.'), "$key: $p salah cakupan");
            }
        }
    }
}
