<?php

namespace App\Http\Controllers;

use App\Models\CreditTransaction;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function privacy(): View
    {
        return view('account.privacy');
    }

    public function export(Request $request)
    {
        $user = $request->user();

        $data = [
            'account' => $user->only(['id', 'name', 'email', 'created_at']),
            'wallet' => $user->wallet?->only(['balance']),
            'investigations' => $user->investigations()->with('runs')->get()->toArray(),
            'payments' => $user->payments()->get()->toArray(),
            'credit_transactions' => CreditTransaction::query()->where('user_id', $user->id)->get()->toArray(),
        ];

        $filename = 'conta-'.$user->id.'-'.now()->format('YmdHis').'.json';

        return response()->streamDownload(
            function () use ($data) {
                echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            },
            $filename,
            ['Content-Type' => 'application/json'],
        );
    }
}
