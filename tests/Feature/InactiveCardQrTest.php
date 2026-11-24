<?php

namespace Tests\Feature;

use App\Actions\Cards\ShareQr;
use App\Enums\CardStatus;
use App\Models\CardShareCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Kartu yang tidak aktif (hilang, dijeda, dicabut) tidak boleh menampilkan QR maupun membawa ke profil lewat QR. */
class InactiveCardQrTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_qr_image_is_served_only_for_active_cards(): void
    {
        $active = $this->makeCard(CardStatus::Active, $this->makeUser());
        $this->get($this->host('tap', "/c/{$active->card_token}/qr.svg"))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');

        foreach ([CardStatus::Lost, CardStatus::Paused] as $status) {
            $card = $this->makeCard($status, $this->makeUser('x'.$status->value.'@example.com', 'X'));
            $this->get($this->host('tap', "/c/{$card->card_token}/qr.svg"))->assertNotFound();
        }
    }

    public function test_rotating_share_code_does_not_redeem_for_a_lost_card(): void
    {
        $card = $this->makeCard(CardStatus::Active, $this->makeUser());
        $code = CardShareCode::create(['card_id' => $card->id, 'code' => 'abcdefghijkl', 'starts_at' => now(), 'expires_at' => now()->addDay()]);
        $this->assertNotNull(ShareQr::redeem($code->code), 'kartu aktif: QR berganti tetap membawa ke profil');

        $card->update(['status' => CardStatus::Lost]);
        $this->assertNull(ShareQr::redeem($code->code), 'kartu hilang: QR lama tidak lagi membawa ke profil');
    }
}
