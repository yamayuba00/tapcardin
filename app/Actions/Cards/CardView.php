<?php

namespace App\Actions\Cards;

use App\Enums\TenantStatus;
use App\Enums\TenantType;
use App\Models\BioBlock;
use App\Models\BioPage;
use App\Models\Card;
use App\Models\Profile;
use App\Models\ProfileField;
use App\Models\Tenant;
use App\Support\Bio\BioTheme;
use App\Tenancy\TenantScope;
use Illuminate\Support\Facades\Cache;

/**
 * Read model resolver: kartu + profil + field + brand, di-cache `card:{token}` 300 detik (BSD §7.2).
 * Dibangun di luar konteks tenant (TRD §6.2), hanya berisi data skalar agar aman diserialisasi.
 */
final class CardView
{
    public function __construct(
        public readonly int $cardId,
        public readonly string $token,
        public readonly string $shortCode,
        public readonly string $status,
        public readonly ?string $chipType,
        public readonly ?int $tenantId,
        public readonly bool $tenantSuspended,
        public readonly bool $isCompanyPool,
        public readonly ?string $tenantName,
        public readonly array $brand,
        public readonly ?int $holderUserId,
        public readonly ?int $profileId,
        public readonly ?array $profile,
        /** @var list<array{id:string,type:string,label:string,value:string,is_primary:bool,in_vcard:bool}> */
        public readonly array $fields,
        /** Blok halaman bio aktif (null = profil tanpa halaman → tampilan lama: daftar kontak). */
        public readonly ?array $blocks = null,
        /** Kartu digital: tap memakai kuota paket (TapQuota). */
        public readonly bool $digital = false,
    ) {}

    public static function cached(string $token): ?self
    {
        // Disimpan sebagai array: cache tidak meng-unserialize objek (config cache.serializable_classes = false).
        $data = Cache::remember("card:$token", 300, fn () => self::build($token)?->toArray() ?? []);

        return $data ? new self(...$data) : null;
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function build(string $token): ?self
    {
        $card = Card::acrossTenants()->where('card_token', $token)->first();
        if (! $card) {
            return null;
        }

        return self::fromCard($card);
    }

    public static function fromCard(Card $card): self
    {
        $tenant = $card->tenant_id ? Tenant::find($card->tenant_id) : null;
        $profile = $card->profile_id
            ? Profile::withoutGlobalScope(TenantScope::class)->whereNull('archived_at')->find($card->profile_id)
            : null;
        $fields = $profile
            ? ProfileField::withoutGlobalScope(TenantScope::class)->where('profile_id', $profile->id)
                ->where('is_visible', true)->orderBy('sort')->orderBy('id')->get()
                ->map(fn (ProfileField $f) => [
                    'id' => $f->public_id,
                    'type' => $f->type,
                    'label' => $f->label,
                    'value' => $f->value,
                    'meta' => $f->meta ?? [],
                    'is_primary' => $f->is_primary,
                    'in_vcard' => $f->in_vcard,
                ])->all()
            : [];

        $page = $profile
            ? BioPage::withoutGlobalScope(TenantScope::class)->where('profile_id', $profile->id)->where('is_active', true)->first()
            : null;
        $blocks = $page
            ? BioBlock::withoutGlobalScope(TenantScope::class)->where('bio_page_id', $page->id)->where('is_visible', true)
                ->orderBy('sort')->orderBy('id')->get()
                ->map(fn (BioBlock $b) => ['id' => $b->public_id, 'type' => $b->type, 'data' => $b->data ?? []])->all()
            : null;

        $isCompany = $tenant && $tenant->type !== TenantType::Personal;

        return new self(
            cardId: $card->id,
            token: $card->card_token,
            shortCode: $card->short_code,
            status: $card->status->value,
            chipType: $card->chip_type?->value,
            tenantId: $card->tenant_id,
            tenantSuspended: $tenant?->status === TenantStatus::Suspended,
            isCompanyPool: $isCompany && $card->holder_user_id === null,
            tenantName: $isCompany ? $tenant->name : null,
            brand: $isCompany ? ($tenant->brand ?? []) + ['logo_path' => $tenant->logo_path] : [],
            holderUserId: $card->holder_user_id,
            profileId: $profile?->id,
            profile: $profile ? [
                'display_name' => $profile->display_name,
                'title' => $profile->title,
                'company' => $profile->company,
                'bio' => $profile->bio,
                'avatar_path' => $profile->avatar_path,
                'vcard_photo_path' => $profile->vcard_photo_path,
                'theme' => BioTheme::resolve($profile->theme),
                'locale' => $profile->locale,
                'is_indexable' => $profile->is_indexable,
                'show_exchange_form' => $profile->show_exchange_form,
            ] : null,
            fields: $fields,
            blocks: $blocks,
            digital: $card->isDigital(),
        );
    }

    /** Preview editor profil: data profil tanpa kartu nyata (tidak mencatat tap). */
    public static function preview(Profile $profile): self
    {
        $card = new Card([
            'card_token' => 'preview000',
            'short_code' => '00000000',
            'status' => 'active',
            'chip_type' => 'ntag213',
            'tenant_id' => $profile->tenant_id,
            'holder_user_id' => $profile->user_id,
            'profile_id' => $profile->id,
        ]);
        $card->id = 0;

        return self::fromCard($card);
    }

    /** @return list<array> */
    public function fieldsOfType(string ...$types): array
    {
        return array_values(array_filter($this->fields, fn ($f) => in_array($f['type'], $types, true)));
    }

    public function primary(string ...$types): ?array
    {
        $list = $this->fieldsOfType(...$types);
        foreach ($list as $f) {
            if ($f['is_primary']) {
                return $f;
            }
        }

        return $list[0] ?? null;
    }
}
