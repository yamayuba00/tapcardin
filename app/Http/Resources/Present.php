<?php

namespace App\Http\Resources;

use App\Models\Card;
use App\Models\Connection;
use App\Models\LeadActivity;
use App\Models\LeadReminder;
use App\Models\LeadStage;
use App\Models\Profile;
use App\Models\ProfileField;
use App\Support\Bio\BioTheme;
use Illuminate\Support\Facades\Storage;

/** Bentuk JSON untuk pulau Vue. ID numerik tidak pernah keluar (BSD §1). */
final class Present
{
    public static function card(Card $c, ?Profile $profile = null): array
    {
        $profile ??= $c->profile_id ? $c->profile : null;

        return [
            'token' => $c->card_token,
            'short_code' => $c->formattedShortCode(),
            'label' => $c->label,
            'status' => $c->status->value,
            'status_label' => $c->status->label(),
            'tone' => $c->status->tone(),
            'form' => $c->form ?? Card::PHYSICAL,
            'chip_type' => $c->chip_type?->value,
            'profile' => $profile ? ['id' => $profile->public_id, 'label' => $profile->label, 'display_name' => $profile->display_name] : null,
            'url' => $c->resolverUrl(),
            'qr_url' => $c->qrUrl(),
            'activated_at' => $c->activated_at?->toIso8601String(),
            'last_tap_at' => $c->last_tap_at?->toIso8601String(),
            'lost_at' => $c->lost_at?->toIso8601String(),
            'replacement_requested_at' => $c->replacement_requested_at?->toIso8601String(),
            'allowed' => array_map(fn ($s) => $s->value, $c->allowedNext()),
        ];
    }

    public static function profile(Profile $p, bool $withFields = false): array
    {
        $out = [
            'id' => $p->public_id,
            'label' => $p->label,
            'display_name' => $p->display_name,
            'title' => $p->title,
            'company' => $p->company,
            'bio' => $p->bio,
            'avatar_url' => $p->avatar_path ? Storage::disk('public')->url($p->avatar_path) : null,
            'theme' => BioTheme::resolve($p->theme),
            'locale' => $p->locale,
            'is_indexable' => $p->is_indexable,
            'show_exchange_form' => $p->show_exchange_form,
            'cards_count' => $p->cards_count ?? null,
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
        if ($withFields) {
            $out['fields'] = $p->fields->map(fn (ProfileField $f) => self::field($f))->all();
        }

        return $out;
    }

    public static function reminder(LeadReminder $r, bool $withLead = false): array
    {
        return [
            'id' => $r->public_id,
            'due_at' => $r->due_at->toIso8601String(),
            'note' => $r->note,
            'is_auto' => $r->is_auto,
            'done_at' => $r->done_at?->toIso8601String(),
            'overdue' => ! $r->done_at && $r->due_at->isPast(),
        ] + ($withLead && $r->connection ? ['lead' => [
            'id' => $r->connection->public_id, 'name' => $r->connection->name, 'company' => $r->connection->company,
            'phone' => $r->connection->phone_e164, 'stage' => $r->connection->stage ? self::stage($r->connection->stage) : null,
        ]] : []);
    }

    public static function field(ProfileField $f): array
    {
        return [
            'id' => $f->public_id,
            'type' => $f->type,
            'label' => $f->label,
            'value' => $f->value,
            'meta' => (object) ($f->meta ?? []),
            'image_url' => ($f->contactType?->input ?? null) === 'image' ? Storage::disk('public')->url($f->value) : null,
            'is_primary' => $f->is_primary,
            'is_visible' => $f->is_visible,
            'in_vcard' => $f->in_vcard,
            'is_locked' => $f->is_locked,
            'click_count' => $f->click_count,
        ];
    }

    public static function stage(LeadStage $s): array
    {
        return ['id' => $s->public_id, 'name' => $s->name, 'kind' => $s->kind->value, 'color' => $s->color, 'is_default' => $s->is_default];
    }

    public static function connection(Connection $c, bool $full = false): array
    {
        $out = [
            'id' => $c->public_id,
            'name' => $c->name,
            'company' => $c->company,
            'job_title' => $c->job_title,
            'email' => $c->email,
            'phone' => $c->phone_e164 ?? $c->phone,
            'source' => $c->source->value,
            'stage' => $c->stage ? self::stage($c->stage) : null,
            'tags' => $c->tags,
            'assignee' => $c->assignee ? ['id' => $c->assignee->public_id, 'name' => $c->assignee->name] : null,
            'note' => $c->note,
            'created_at' => $c->created_at?->toIso8601String(),
            'last_activity_at' => $c->last_activity_at?->toIso8601String(),
            'next_followup_at' => $c->next_followup_at?->toIso8601String(),
            'value_amount' => $c->value_amount,
            'event' => $c->relationLoaded('event') && $c->event ? ['id' => $c->event->public_id, 'name' => $c->event->name] : null,
        ];
        if ($full) {
            $out += [
                'external_url' => $c->external_url,
                'reminders' => $c->reminders()->whereNull('done_at')->get()->map(fn ($r) => self::reminder($r))->all(),
                'lost_reason' => $c->lost_reason,
                'consent_at' => $c->consent_at?->toIso8601String(),
                'card' => $c->card ? ['token' => $c->card->card_token, 'label' => $c->card->label] : null,
                'activities' => $c->activities->map(fn (LeadActivity $a) => [
                    'id' => $a->id,
                    'type' => $a->type->value,
                    'body' => $a->body,
                    'meta' => $a->meta,
                    'user' => $a->user?->name,
                    'occurred_at' => $a->occurred_at->toIso8601String(),
                ])->all(),
            ];
        }

        return $out;
    }
}
