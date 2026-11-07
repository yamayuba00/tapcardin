<?php

namespace App\Actions\Events;

use App\Models\Event;
use App\Models\EventCard;
use App\Models\TenantMember;
use App\Tenancy\TenantScope;
use Carbon\CarbonInterface;

/**
 * Event yang berlaku untuk sebuah kartu pada waktu tertentu — dipakai pencatatan tap, Tukar Kontak, formulir bio,
 * dan "Bertemu di {Event}". Urutan: (1) event yang kartu ini di-assign dan sedang berlangsung (paling baru mulai);
 * (2) Mode Event pemegang kartu ("Saya sedang di event ini"). Bisa dipanggil di luar konteks tenant.
 */
final class EventForCard
{
    public static function at(?int $tenantId, ?int $cardId, ?int $holderUserId, CarbonInterface $at): ?Event
    {
        if (! $tenantId) {
            return null;
        }
        $events = Event::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId);
        if ($cardId) {
            $assigned = (clone $events)
                ->whereIn('id', EventCard::withoutGlobalScope(TenantScope::class)->where('card_id', $cardId)->select('event_id'))
                ->where('starts_at', '<=', $at)->where('ends_at', '>=', $at)
                ->orderByDesc('starts_at')->first();
            if ($assigned) {
                return $assigned;
            }
        }
        if ($holderUserId) {
            $member = TenantMember::withoutGlobalScope(TenantScope::class)->where(['tenant_id' => $tenantId, 'user_id' => $holderUserId])->first();
            if ($member?->active_event_id && $member->active_event_until?->isAfter($at)) {
                return (clone $events)->find($member->active_event_id);
            }
        }

        return null;
    }
}
