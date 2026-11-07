<?php

namespace App\Http\Controllers\Public;

use App\Actions\Events\EventForCard;
use App\Actions\Leads\CreateConnection;
use App\Enums\LeadSource;
use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\BioBlock;
use App\Models\FormSubmission;
use App\Models\ProductEvent;
use App\Models\Tenant;
use App\Support\Phone;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/c/{token}/forms/{block} — kirim formulir bio link. Nama + WhatsApp/email selalu diminta (agar bisa
 * dihubungi balik), lalu jawaban pertanyaan buatan pemilik. Hasilnya koneksi bersumber `bio_form` (dedup sama
 * dengan Tukar Kontak) + catatan jawaban di form_submissions, tercatat ke kartu & event yang sedang berlangsung.
 */
class PublicFormController extends Controller
{
    public function submit(Request $request, string $token, string $block, CurrentTenant $current, CreateConnection $create): JsonResponse
    {
        $view = PublicCardController::activeView($token);
        $form = collect($view->blocks ?? [])->first(fn ($b) => $b['id'] === $block && $b['type'] === 'form');
        abort_unless($form, 404);
        $f = $form['data'];

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'whatsapp' => ['nullable', 'string', 'max:32', ...match ($f['contact']) {
                'whatsapp' => ['required'], 'email' => [], default => ['required_without:email']
            }],
            'email' => ['nullable', 'email:rfc', 'max:190', ...match ($f['contact']) {
                'email' => ['required'], 'whatsapp' => [], default => ['required_without:whatsapp']
            }],
            'answers' => ['nullable', 'array'],
            'consent' => ['accepted'],
            'website' => ['nullable', 'string'], // honeypot
            't' => ['nullable', 'string'],       // waktu render form (terenkripsi)
        ], [
            'name.required' => 'Isi nama Anda.',
            'whatsapp.required' => 'Isi nomor WhatsApp.',
            'email.required' => 'Isi email.',
            'whatsapp.required_without' => 'Isi WhatsApp atau email.',
            'email.required_without' => 'Isi WhatsApp atau email.',
            'consent.accepted' => 'Centang persetujuan untuk melanjutkan.',
        ]);
        if (($data['whatsapp'] ?? null) && ! Phone::toE164($data['whatsapp'])) {
            throw ValidationException::withMessages(['whatsapp' => 'Nomor WhatsApp belum valid.']);
        }
        $answers = $this->answers($f['fields'], (array) ($data['answers'] ?? []));

        // Spam → sukses palsu tanpa menyimpan agar bot tidak belajar (sama dengan Tukar Kontak).
        if (! empty($data['website']) || PublicCardController::tooFast($data['t'] ?? null)) {
            return response()->json(['data' => ['status' => 'sent', 'message' => $f['success']]], 201);
        }

        $tenant = Tenant::findOrFail($view->tenantId);
        $current->run($tenant, function () use ($create, $data, $view, $tenant, $form, $f, $answers) {
            $event = EventForCard::at($tenant->id, $view->cardId, $view->holderUserId, now());
            $summary = $f['title']."\n".collect($answers)->map(fn ($a) => '• '.$a['label'].': '.(is_array($a['value']) ? implode(', ', $a['value']) : $a['value']))->implode("\n");
            $connectionId = null;
            try {
                $connectionId = $create->handle([
                    'name' => $data['name'],
                    'phone' => $data['whatsapp'] ?? null,
                    'email' => $data['email'] ?? null,
                    'note' => mb_substr($summary, 0, 5000),
                    'consent' => true,
                ], LeadSource::BioForm, $view->holderUserId, ['card_id' => $view->cardId, 'event_id' => $event?->id])['connection']->id;
            } catch (ApiError $e) {
                // batas paket pemilik tidak boleh membuat pengunjung gagal; jawaban tetap disimpan
                Log::warning('Formulir bio: koneksi tidak dibuat: '.$e->getMessage(), ['tenant_id' => $tenant->id, 'code' => $e->errorCode]);
            }
            FormSubmission::create([
                'bio_block_id' => BioBlock::withoutGlobalScope(TenantScope::class)->where('public_id', $form['id'])->value('id'),
                'connection_id' => $connectionId,
                'card_id' => $view->cardId,
                'event_id' => $event?->id,
                'form_title' => mb_substr($f['title'], 0, 80),
                'answers' => [
                    ['label' => 'Nama', 'type' => 'text', 'value' => $data['name']],
                    ...(($data['whatsapp'] ?? null) ? [['label' => 'WhatsApp', 'type' => 'phone', 'value' => $data['whatsapp']]] : []),
                    ...(($data['email'] ?? null) ? [['label' => 'Email', 'type' => 'email', 'value' => $data['email']]] : []),
                    ...$answers,
                ],
            ]);
            ProductEvent::record('bio_form_submitted', $tenant->id, null, ['card' => $view->token]);
        });

        return response()->json(['data' => ['status' => 'sent', 'message' => $f['success']]], 201);
    }

    /**
     * Validasi jawaban per pertanyaan; kunci = ID pertanyaan. Pertanyaan yang tidak dijawab dan tidak wajib dilewati.
     *
     * @return list<array{label:string, type:string, value:string|list<string>}>
     */
    private function answers(array $fields, array $input): array
    {
        $errors = [];
        $out = [];
        foreach ($fields as $q) {
            $raw = $input[$q['id']] ?? null;
            $empty = $raw === null || $raw === '' || $raw === [];
            if ($empty) {
                if ($q['required']) {
                    $errors["answers.{$q['id']}"] = 'Wajib diisi.';
                }

                continue;
            }
            $value = null;
            $error = null;
            if ($q['type'] === 'multi') {
                $picked = array_values(array_unique(array_map('strval', (array) $raw)));
                array_diff($picked, $q['options']) ? $error = 'Pilihan tidak valid.' : $value = $picked;
            } elseif (! is_scalar($raw)) {
                $error = 'Jawaban tidak valid.';
            } else {
                $v = trim((string) $raw);
                [$value, $error] = match ($q['type']) {
                    'text' => [mb_substr($v, 0, 200), null],
                    'textarea' => [mb_substr($v, 0, 2000), null],
                    'email' => filter_var($v, FILTER_VALIDATE_EMAIL) ? [mb_substr($v, 0, 190), null] : [null, 'Email belum valid.'],
                    'phone' => Phone::toE164($v) ? [Phone::toE164($v), null] : [null, 'Nomor belum valid.'],
                    'number' => preg_match('/^-?\d{1,15}([.,]\d{1,4})?$/', $v) ? [$v, null] : [null, 'Isi dengan angka.'],
                    'select' => in_array($v, $q['options'], true) ? [$v, null] : [null, 'Pilihan tidak valid.'],
                    'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)) ? [$v, null] : [null, 'Tanggal belum valid.'],
                    default => [null, 'Jawaban tidak valid.'],
                };
            }
            if ($error) {
                $errors["answers.{$q['id']}"] = $error;

                continue;
            }
            $out[] = ['label' => $q['label'], 'type' => $q['type'], 'value' => $value];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }
}
