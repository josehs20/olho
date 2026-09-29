<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\InsufficientCreditsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminCreditAdjustmentRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AuditLogger;
use App\Services\CreditLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(
        private readonly CreditLedgerService $credits,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $users = User::query()
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', "%{$request->query('q')}%")->orWhere('email', 'like', "%{$request->query('q')}%"))
            ->with('wallet')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.users.index', compact('users'));
    }

    public function adjustCredits(AdminCreditAdjustmentRequest $request, User $user): RedirectResponse
    {
        $wallet = Wallet::firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        $amount = (int) $request->integer('amount');
        $reason = (string) $request->string('reason');

        try {
            $this->credits->adminAdjust($user, $wallet, $amount, $reason, 'admin-'.Str::uuid());
        } catch (InsufficientCreditsException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        $this->audit->log(
            $request->user(),
            'admin.credit_adjustment',
            $user,
            ['amount' => $amount, 'reason' => $reason],
            $request->ip(),
        );

        return back()->with('status', 'Ajuste de créditos realizado.');
    }
}
