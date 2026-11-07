<?php

namespace App\Http\Controllers\Platform;

use App\Authz\PlatformAccess;
use App\Enums\CardStatus;
use App\Models\ApprovalRequest;
use App\Models\Batch;
use App\Models\Card;
use App\Models\Order;
use App\Models\PaymentReview;
use App\Models\Refund;
use App\Models\Tap;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** PL-01 (dasar) — kesehatan bisnis & operasional. */
class DashboardController extends PlatformController
{
    public function show(PlatformAccess $access): JsonResponse
    {
        $this->can('dashboard.ops');

        $m = Cache::remember('platform:metrics:'.now()->format('Ymd-H').'-'.intdiv(now()->minute, 5), 300, function () {
            $cards = Card::acrossTenants()->select('status', DB::raw('count(*) n'))->groupBy('status')->pluck('n', 'status');
            $shipped = Card::acrossTenants()->whereNotNull('shipped_at')->where('shipped_at', '>=', now()->subDays(90));
            $shippedCount = (clone $shipped)->count();
            $activated7 = (clone $shipped)->whereNotNull('activated_at')->whereRaw("activated_at <= shipped_at + interval '7 days'")->count();
            $taps = Tap::acrossTenants()->where('created_at', '>=', now()->subDays(30))
                ->selectRaw("(created_at at time zone 'Asia/Jakarta')::date d, count(*) n")->groupBy('d')->pluck('n', 'd');
            $series = [];
            for ($i = 29; $i >= 0; $i--) {
                $d = now('Asia/Jakarta')->subDays($i)->toDateString();
                $series[] = ['date' => $d, 'value' => (int) ($taps[$d] ?? 0)];
            }

            return [
                'users' => User::count(),
                'users_7' => User::where('created_at', '>=', now()->subDays(7))->count(),
                'tenants_company' => Tenant::where('type', 'company')->count(),
                'cards' => collect(CardStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($cards[$s->value] ?? 0)])->all(),
                'activation_rate' => $shippedCount ? round($activated7 / $shippedCount * 100) : null,
                'taps_today' => Tap::acrossTenants()->where('created_at', '>=', now('Asia/Jakarta')->startOfDay()->utc())->count(),
                'taps_30' => array_sum(array_column($series, 'value')),
                'series' => $series,
            ];
        });

        $m['batches_open'] = Batch::whereNotIn('status', ['completed', 'cancelled'])->latest()->limit(5)->get()
            ->map(fn (Batch $b) => ['id' => $b->public_id, 'code' => $b->code, 'quantity' => $b->quantity,
                'chip' => $b->chip_type->value, 'status' => $b->status->value, 'status_label' => $b->status->label()]);
        // antrean "perlu tindakan" — hitungan hanya untuk yang berwenang menanganinya, tidak di-cache
        $m['attention'] = [
            'payment_reviews' => $access->has('finance.reviews') ? PaymentReview::where('status', 'open')->count() : null,
            'approvals' => $access->has('finance.approve') ? ApprovalRequest::where('status', 'pending')->where('expires_at', '>', now())->count() : null,
            'refunds_decide' => $access->has('finance.refund.approve') ? Refund::where('status', 'requested')->count() : null,
            'refunds_payout' => $access->has('finance.refund.payout') ? Refund::where('status', 'approved')->count() : null,
            'orders_review' => $access->has('orders.review') ? Order::acrossTenants()->where('status', 'paid')->where('review_status', 'pending')->count() : null,
        ];
        $m['show_business'] = $access->has('dashboard.business');
        $m['business'] = $m['show_business'] ? Cache::remember('platform:finance:'.intdiv(now()->timestamp, 300), 300, fn () => FinanceController::metrics()) : null;
        $m['replacements_pending'] = Card::acrossTenants()->whereNotNull('replacement_requested_at')->where('status', CardStatus::Lost->value)
            ->whereNotExists(fn ($b) => $b->from('batches')->whereColumn('batches.replaces_card_id', 'cards.id'))->count();

        return $this->ok($m);
    }
}
