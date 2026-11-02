<?php

namespace App\Actions\Leads;

use App\Enums\ActivityType;
use App\Enums\LeadSource;
use App\Models\Connection;
use App\Models\LeadActivity;
use App\Models\LeadStage;
use App\Support\Notify;
use App\Support\Phone;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;

/**
 * Membuat lead/koneksi di tenant aktif. Dedup (LD-07): nomor E.164 atau email yang sama
 * di workspace yang sama → lead lama dipakai dan diberi aktivitas baru, bukan lead baru.
 */
final class CreateConnection
{
    public function __construct(private readonly CurrentTenant $current) {}

    /**
     * @param  array{name:string, email?:?string, phone?:?string, company?:?string, job_title?:?string,
     *               note?:?string, external_url?:?string, consent?:bool, tags?:list<string>}  $data
     * @param  array{card_id?:int, tap_id?:int, event_id?:int, counterpart_user_id?:int, counterpart_card_id?:int, quiet?:bool}  $refs
     * @return array{connection: Connection, duplicate: bool}
     */
    public function handle(array $data, LeadSource $source, ?int $collectorUserId, array $refs = []): array
    {
        $tenant = $this->current->require();
        $email = isset($data['email']) && $data['email'] !== '' ? trim($data['email']) : null;
        $phone = isset($data['phone']) && $data['phone'] !== '' ? trim($data['phone']) : null;
        $e164 = Phone::toE164($phone);
        $emailNorm = $email ? strtolower($email) : null;

        return DB::transaction(function () use ($tenant, $data, $source, $collectorUserId, $refs, $email, $phone, $e164, $emailNorm) {
            $existing = ($e164 || $emailNorm) ? Connection::query()
                ->whereNull('merged_into_id')
                ->where(fn ($q) => $q
                    ->when($e164, fn ($w) => $w->orWhere('phone_e164', $e164))
                    ->when($emailNorm, fn ($w) => $w->orWhere('email_norm', $emailNorm)))
                ->orderBy('id')->lockForUpdate()->first() : null;

            if ($existing) {
                $existing->fill(array_filter([
                    'company' => $existing->company ?: ($data['company'] ?? null),
                    'job_title' => $existing->job_title ?: ($data['job_title'] ?? null),
                    'email' => $existing->email ?: $email,
                    'email_norm' => $existing->email_norm ?: $emailNorm,
                    'phone' => $existing->phone ?: $phone,
                    'phone_e164' => $existing->phone_e164 ?: $e164,
                ]))->forceFill(['last_activity_at' => now()])->save();

                $this->activity($existing, $refs['tap_id'] ?? null ? ActivityType::Tap : ActivityType::Created,
                    $data['note'] ?? null, ['source' => $source->value, 'collector_user_id' => $collectorUserId, 'deduplicated' => true]);

                return ['connection' => $existing, 'duplicate' => true];
            }

            $connection = Connection::create([
                'source' => $source,
                'captured_by_user_id' => $collectorUserId,
                'assignee_user_id' => $collectorUserId,
                'card_id' => $refs['card_id'] ?? null,
                'tap_id' => $refs['tap_id'] ?? null,
                'event_id' => $refs['event_id'] ?? null,
                'counterpart_user_id' => $refs['counterpart_user_id'] ?? null,
                'counterpart_card_id' => $refs['counterpart_card_id'] ?? null,
                'stage_id' => LeadStage::where('is_default', true)->value('id'),
                'name' => mb_substr(trim($data['name']), 0, 120),
                'email' => $email,
                'email_norm' => $emailNorm,
                'phone' => $phone,
                'phone_e164' => $e164,
                'company' => $data['company'] ?? null,
                'job_title' => $data['job_title'] ?? null,
                'external_url' => $data['external_url'] ?? null,
                'note' => $data['note'] ?? null,
                'tags' => array_values(array_unique(array_filter(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 30), $data['tags'] ?? [])))),
                'consent_at' => ! empty($data['consent']) ? now() : null,
                'consent_text_version' => ! empty($data['consent']) ? config('tapcard.consent_text_version') : null,
                'last_activity_at' => now(),
                'scanned_at' => in_array($source, [LeadSource::ScanTapcard, LeadSource::ScanExternal], true) ? now() : null,
            ]);
            $this->activity($connection, ActivityType::Created, null, ['source' => $source->value]);

            // impor massal: tanpa notifikasi & pengingat per baris (ringkasan dikirim sekali oleh job)
            if (empty($refs['quiet'])) {
                Followups::auto($connection, $tenant);
            }
            if ($collectorUserId && empty($refs['quiet'])) {
                Notify::user($collectorUserId, $tenant->id, 'lead_new', [
                    'id' => $connection->public_id,
                    'name' => $connection->name,
                    'company' => $connection->company,
                    'source' => $source->value,
                ]);
            }

            return ['connection' => $connection, 'duplicate' => false];
        });
    }

    private function activity(Connection $c, ActivityType $type, ?string $body, array $meta): void
    {
        LeadActivity::create([
            'connection_id' => $c->id,
            'user_id' => auth()->id(),
            'type' => $type,
            'body' => $body,
            'meta' => $meta,
            'occurred_at' => now(),
        ]);
    }
}
