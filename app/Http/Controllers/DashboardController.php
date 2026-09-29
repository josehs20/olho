<?php

namespace App\Http\Controllers;

use App\Models\InvestigationRun;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $wallet = Wallet::firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        $creditsSpent = InvestigationRun::query()
            ->whereHas('investigation', fn ($q) => $q->where('user_id', $user->id))
            ->sum('credits_consumed');

        return view('dashboard', [
            'balance' => (int) $wallet->balance,
            'creditsSpent' => (int) $creditsSpent,
            'recentInvestigations' => $user->investigations()->latest()->limit(5)->get(),
            'recentPayments' => $user->payments()->latest()->limit(5)->get(),
        ]);
    }
}
