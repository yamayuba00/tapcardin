<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Jawaban formulir bio link. `answers` = salinan [{label, type, value}] saat dikirim (tetap utuh bila formulir diubah). */
class FormSubmission extends Model
{
    use BelongsToTenant, HasPublicId;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['answers' => 'array'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function present(): array
    {
        return [
            'id' => $this->public_id,
            'title' => $this->form_title,
            'answers' => $this->answers,
            'card' => $this->card ? ['token' => $this->card->card_token, 'label' => $this->card->label ?: 'Kartu '.$this->card->formattedShortCode()] : null,
            'event' => $this->event?->name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
