<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Models\Investigation;
use App\Models\Payment;
use App\Models\SourceRun;
use App\Models\User;
use App\Services\VisitorCounter;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(VisitorCounter $visits): View
    {
        return view('admin.dashboard', [
            'usersCount' => User::count(),
            'investigationsCount' => Investigation::count(),
            'paymentsCount' => Payment::count(),
            'creditsSold' => (int) CreditTransaction::where('type', 'purchase_credit')->sum('amount'),
            'sourceErrors' => SourceRun::whereIn('status', ['failed', 'timeout'])->count(),
            'visits' => $visits->totals(),
        ]);
    }
}
