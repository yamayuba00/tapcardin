<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\Cards\ChangeCardStatus;
use App\Authz\PermissionResolver;
use App\Authz\PlanFeatures;
use App\Enums\CardStatus;
use App\Enums\MemberStatus;
use App\Exceptions\ApiError;
use App\Models\Card;
use App\Models\CardLoginKey;
use App\Models\Connection;
use App\Models\Role;
use App\Models\StaffAccount;
use App\Models\Subscription;
use App\Models\Tap;
use App\Models\TenantMember;
use App\Models\User;
use App\Support\Audit;
use App\Support\Http\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Area perusahaan (subdomain / domain kustom): ringkasan, karyawan, kartu karyawan, rekap lead.
 * Karyawan ditambahkan owner/admin dengan email akun yang sudah terdaftar — tidak ada daftar pengguna yang bisa dijelajah.
 */
class CompanyController extends WorkspaceController
{
    /** Peran yang boleh diberikan di sini; Owner hanya lewat pemindahan kepemilikan di panel. */
    private const ROLES = ['admin', 'manager', 'billing', 'member', 'event_staff'];

    /** GET /api/company/summary */
    public function summary(): JsonResponse
    {
        $this->company('analytics.view.all');
        $t = $this->tenant();
        $since = now()->subDays(30);
        $sub = Subscription::where('tenant_id', $t->id)->first();

        return response()->json(['data' => [
            'cards_active' => Card::where('form', Card::PHYSICAL)->whereIn('status', [CardStatus::Active->value, CardStatus::Paused->value])->count(),
            'cards_max' => PlanFeatures::limit($t, 'cards_active'),
            'cards_pool' => Card::where('form', Card::PHYSICAL)->where('status', CardStatus::Unclaimed->value)->whereNull('assigned_user_id')->count(),
            'members' => TenantMember::where('status', MemberStatus::Active->value)->count(),
            'subscription' => $sub ? [
                'status' => $sub->status,
                'active_until' => ($sub->status === 'trialing' ? ($sub->trial_ends_at ?? $sub->current_period_end) : $sub->current_period_end)?->toIso8601String(),
            ] : null,
            'plan' => $t->plan?->name,
            'taps_30' => Tap::where('created_at', '>=', $since)->count(),
            'leads_30' => Connection::where('created_at', '>=', $since)->count(),
        ]]);
    }

    /** GET /api/company/members */
    public function members(Request $request): JsonResponse
    {
        $this->company('members.invite');
        $q = TenantMember::with(['user', 'role'])->join('users', 'users.id', '=', 'tenant_members.user_id')->select('tenant_members.*')
            ->when($request->query('status'), fn ($w, $s) => $w->where('tenant_members.status', $s));
        $sorts = ['name' => 'users.name', 'email' => 'users.email', 'status' => 'tenant_members.status', 'joined_at' => 'tenant_members.joined_at'];
        $page = Table::paginate($q, $request, $sorts, 'name', ['users.name', 'users.email'], 25);
        $ownerId = $this->tenant()->owner_user_id;

        return Table::json($page, fn (TenantMember $m) => $this->member($m, $ownerId), $request, [
            'roles' => Role::where('is_system', true)->whereIn('key', self::ROLES)->get(['key', 'name'])
                ->sortBy(fn ($r) => array_search($r->key, self::ROLES, true))->values(),
        ]);
    }

    /** POST /api/company/members {email, role} — hanya akun pengguna yang sudah terdaftar, bukan akun staf panel. */
    public function add(Request $request): JsonResponse
    {
        $this->company('members.invite');
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'role' => ['required', Rule::in(self::ROLES)],
        ], ['email.email' => 'Tulis email yang valid.']);
        $email = strtolower(trim($data['email']));
        $notFound = fn (string $msg) => new ApiError('member_not_found', $msg, 422, ['fields' => ['email' => [$msg]]]);
        if (StaffAccount::whereRaw('lower(email) = ?', [$email])->exists()) {
            throw $notFound('Email ini akun staf TapCard dan tidak bisa ditambahkan sebagai karyawan.');
        }
        $user = User::whereRaw('lower(email) = ?', [$email])->first();
        if (! $user || $user->isBlocked()) {
            throw $notFound('Email ini belum terdaftar di TapCard. Minta karyawan login sekali dengan Google, lalu tambahkan lagi.');
        }
        $existing = TenantMember::where('user_id', $user->id)->first();
        if ($existing?->status === MemberStatus::Active) {
            throw new ApiError('member_exists', 'Akun ini sudah menjadi anggota.', 422, ['fields' => ['email' => ['Akun ini sudah menjadi anggota.']]]);
        }

        $role = Role::system($data['role']);
        $m = $existing
            ? tap($existing)->update(['role_id' => $role->id, 'status' => MemberStatus::Active->value, 'removed_at' => null, 'removed_by' => null])
            : TenantMember::create(['tenant_id' => $this->tenant()->id, 'user_id' => $user->id, 'role_id' => $role->id, 'status' => MemberStatus::Active->value]);
        PermissionResolver::bump($this->tenant()->id);
        Audit::tenant($this->tenant()->id, 'member.added', $user, null, ['role' => $role->key]);

        return response()->json(['data' => $this->member($m->fresh(['user', 'role']), $this->tenant()->owner_user_id)], 201);
    }

    /** PATCH /api/company/members/{user} {role} */
    public function role(Request $request, string $user): JsonResponse
    {
        $this->company('roles.manage');
        $data = $request->validate(['role' => ['required', Rule::in(self::ROLES)]]);
        $m = $this->editable($user);
        $before = $m->role->key;
        $m->update(['role_id' => Role::system($data['role'])->id]);
        PermissionResolver::bump($this->tenant()->id);
        Audit::tenant($this->tenant()->id, 'member.role_changed', $m->user, ['role' => $before], ['role' => $data['role']]);

        return response()->json(['data' => $this->member($m->fresh(['user', 'role']), $this->tenant()->owner_user_id)]);
    }

    /** POST /api/company/members/{user}/deactivate — karyawan keluar: akses dicabut, kartunya kembali ke stok perusahaan. */
    public function deactivate(string $user, ChangeCardStatus $status): JsonResponse
    {
        $this->company('members.remove');
        $m = $this->editable($user);
        DB::transaction(function () use ($m, $status) {
            $m->update(['status' => MemberStatus::Removed->value, 'removed_at' => now(), 'removed_by' => $this->user()->id]);
            Card::where('form', Card::PHYSICAL)->where(fn ($w) => $w->where('holder_user_id', $m->user_id)->orWhere('assigned_user_id', $m->user_id))
                ->get()->each(fn (Card $c) => $this->release($c, $status));
        });
        PermissionResolver::bump($this->tenant()->id);
        Audit::tenant($this->tenant()->id, 'member.removed', $m->user);

        return response()->json(['data' => $this->member($m->fresh(['user', 'role']), $this->tenant()->owner_user_id)]);
    }

    /** POST /api/company/members/{user}/reactivate */
    public function reactivate(string $user): JsonResponse
    {
        $this->company('members.remove');
        $m = $this->editable($user);
        $m->update(['status' => MemberStatus::Active->value, 'removed_at' => null, 'removed_by' => null]);
        PermissionResolver::bump($this->tenant()->id);
        Audit::tenant($this->tenant()->id, 'member.reactivated', $m->user);

        return response()->json(['data' => $this->member($m->fresh(['user', 'role']), $this->tenant()->owner_user_id)]);
    }

    /** GET /api/company/cards — kartu fisik perusahaan: stok, ditugaskan, dan dipegang karyawan. */
    public function cards(Request $request): JsonResponse
    {
        $this->company('cards.assign.all');
        $q = Card::with(['holder', 'assignee'])->where('form', Card::PHYSICAL)
            ->whereIn('status', [CardStatus::Unclaimed->value, CardStatus::Active->value, CardStatus::Paused->value])
            ->when($request->query('filter') === 'pool', fn ($w) => $w->where('status', CardStatus::Unclaimed->value)->whereNull('assigned_user_id'));
        $page = Table::paginate($q, $request, ['status' => 'status', 'updated_at' => 'updated_at'], '-updated_at', ['short_code', 'label'], 25);

        return Table::json($page, fn (Card $c) => $this->card($c), $request);
    }

    /** PUT /api/company/cards/{token}/assign {user} — stok perusahaan → karyawan (karyawan mengaktifkan sendiri). */
    public function assign(Request $request, string $token): JsonResponse
    {
        $this->company('cards.assign.all');
        $data = $request->validate(['user' => ['required', 'string', 'size:26']]);
        $card = $this->companyCard($token);
        if ($card->status !== CardStatus::Unclaimed || $card->holder_user_id) {
            throw new ApiError('card_in_use', 'Kartu ini sedang dipakai. Tarik dulu dari pemegangnya.');
        }
        $user = User::where('public_id', $data['user'])->first();
        $member = $user ? TenantMember::where('user_id', $user->id)->where('status', MemberStatus::Active->value)->exists() : false;
        if (! $member) {
            throw new ApiError('not_member', 'Pilih karyawan yang masih aktif.');
        }
        $card->update(['assigned_user_id' => $user->id]);
        Audit::tenant($card->tenant_id, 'card.assigned', $card, null, ['user' => $user->public_id]);

        return response()->json(['data' => $this->card($card->fresh(['holder', 'assignee']))]);
    }

    /** DELETE /api/company/cards/{token}/assign — tarik kartu dari karyawan, kembali ke stok perusahaan. */
    public function unassign(string $token, ChangeCardStatus $status): JsonResponse
    {
        $this->company('cards.assign.all');
        $card = $this->companyCard($token);
        DB::transaction(fn () => $this->release($card, $status));
        Audit::tenant($card->tenant_id, 'card.unassigned', $card);

        return response()->json(['data' => $this->card($card->fresh(['holder', 'assignee']))]);
    }

    /** GET /api/company/leads — rekap per karyawan: kartu aktif, tap & lead 30 hari, total lead. */
    public function leads(): JsonResponse
    {
        $this->company('analytics.view.all');
        $since = now()->subDays(30);
        $members = TenantMember::with('user')->where('status', MemberStatus::Active->value)->get();
        $ids = $members->pluck('user_id');
        $count = fn ($q, string $col) => $q->whereIn($col, $ids)->selectRaw("$col as uid, count(*) as n")->groupBy($col)->pluck('n', 'uid');
        $taps = $count(Tap::where('created_at', '>=', $since), 'holder_user_id');
        $leads30 = $count(Connection::where('created_at', '>=', $since), 'assignee_user_id');
        $leads = $count(Connection::query(), 'assignee_user_id');
        $cards = $count(Card::where('form', Card::PHYSICAL)->whereIn('status', [CardStatus::Active->value, CardStatus::Paused->value]), 'holder_user_id');

        return response()->json(['data' => $members->map(fn (TenantMember $m) => [
            'user_id' => $m->user->public_id, 'name' => $m->user->name, 'email' => $m->user->email,
            'cards' => (int) ($cards[$m->user_id] ?? 0), 'taps_30' => (int) ($taps[$m->user_id] ?? 0),
            'leads_30' => (int) ($leads30[$m->user_id] ?? 0), 'leads_total' => (int) ($leads[$m->user_id] ?? 0),
        ])->sortByDesc('leads_30')->values()]);
    }

    private function company(string $permission): void
    {
        if ($this->tenant()->isPersonal()) {
            throw new ApiError('company_only', 'Fitur ini hanya untuk workspace perusahaan.', 404);
        }
        $this->access()->authorize($permission);
    }

    /** Anggota yang boleh diubah di sini: bukan owner dan bukan diri sendiri. */
    private function editable(string $publicId): TenantMember
    {
        $m = TenantMember::with(['user', 'role'])->whereHas('user', fn ($q) => $q->where('public_id', $publicId))->firstOrFail();
        if ($m->user_id === $this->tenant()->owner_user_id) {
            throw new ApiError('owner_protected', 'Owner tidak bisa diubah di sini. Pemindahan owner dilakukan oleh tim TapCard.', 403);
        }
        if ($m->user_id === $this->user()->id) {
            throw new ApiError('self_protected', 'Anda tidak bisa mengubah peran atau menonaktifkan akun sendiri.', 403);
        }

        return $m;
    }

    private function companyCard(string $token): Card
    {
        return Card::with(['holder', 'assignee'])->where('form', Card::PHYSICAL)->where('card_token', $token)->firstOrFail();
    }

    /** Lepas kartu dari karyawan: penugasan dihapus; kartu yang sudah aktif kembali "belum diaktifkan" tanpa profil & kunci login. */
    private function release(Card $card, ChangeCardStatus $status): void
    {
        if ($card->status === CardStatus::Unclaimed) {
            $card->update(['assigned_user_id' => null]);

            return;
        }
        if ($card->status === CardStatus::Paused) {
            $status->handle($card, CardStatus::Active, 'Ditarik dari karyawan');
        }
        if ($card->status === CardStatus::Active) {
            $status->handle($card, CardStatus::Unclaimed, 'Ditarik dari karyawan', extra: ['holder_user_id' => null, 'assigned_user_id' => null, 'profile_id' => null]);
            CardLoginKey::where('card_id', $card->id)->delete();
        }
    }

    private function member(TenantMember $m, ?int $ownerId): array
    {
        return [
            'user_id' => $m->user->public_id, 'name' => $m->user->name, 'email' => $m->user->email,
            'role' => $m->user_id === $ownerId ? 'owner' : $m->role->key, 'role_name' => $m->role->name,
            'status' => $m->status->value, 'is_owner' => $m->user_id === $ownerId,
            'joined_at' => $m->joined_at?->toIso8601String(),
        ];
    }

    private function card(Card $c): array
    {
        $person = fn (?User $u) => $u ? ['id' => $u->public_id, 'name' => $u->name, 'email' => $u->email] : null;

        return [
            'token' => $c->card_token, 'short_code' => $c->formattedShortCode(), 'label' => $c->label,
            'status' => $c->status->value, 'status_label' => $c->status->label(), 'tone' => $c->status->tone(),
            'holder' => $person($c->holder), 'assigned' => $person($c->assignee),
        ];
    }
}
