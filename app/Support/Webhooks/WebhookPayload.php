<?php

namespace App\Support\Webhooks;

use App\Models\Card;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;

/** Isi `data` event webhook. Hanya ID publik (ULID / token kartu) — tidak pernah ID numerik, hash, atau rahasia. */
final class WebhookPayload
{
    public static function envelope(string $eventId, string $type, Tenant $tenant, array $data, ?string $at = null): array
    {
        return [
            'id' => $eventId,
            'type' => $type,
            'created_at' => $at ?? now()->toIso8601String(),
            'workspace' => ['id' => $tenant->public_id, 'name' => $tenant->name, 'slug' => $tenant->slug],
            'data' => $data,
        ];
    }

    public static function user(?User $u): ?array
    {
        return $u ? ['id' => $u->public_id, 'name' => $u->name, 'email' => $u->email] : null;
    }

    public static function lead(Connection $c): array
    {
        $c->loadMissing(['stage', 'assignee', 'card']);

        return [
            'id' => $c->public_id,
            'name' => $c->name,
            'email' => $c->email,
            'phone' => $c->phone_e164 ?? $c->phone,
            'company' => $c->company,
            'job_title' => $c->job_title,
            'source' => $c->source?->value,
            'stage' => $c->stage ? ['id' => $c->stage->public_id, 'name' => $c->stage->name, 'kind' => $c->stage->kind?->value] : null,
            'tags' => array_values($c->tags ?? []),
            'value_amount' => $c->value_amount,
            'note' => $c->note,
            'assignee' => self::user($c->assignee),
            'card' => $c->card ? self::cardRef($c->card) : null,
            'created_at' => $c->created_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }

    public static function cardRef(Card $c): array
    {
        return ['token' => $c->card_token, 'short_code' => $c->formattedShortCode(), 'label' => $c->label];
    }

    public static function card(Card $c): array
    {
        $c->loadMissing(['holder', 'assignee']);

        return self::cardRef($c) + [
            'form' => $c->form,
            'status' => $c->status->value,
            'holder' => self::user($c->holder),
            'assigned_to' => self::user($c->assignee),
            'activated_at' => $c->activated_at?->toIso8601String(),
        ];
    }
}
