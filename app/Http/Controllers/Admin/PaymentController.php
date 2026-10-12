<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Payments\PaymentMethod;
use App\Payments\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function index(Request $request): View
    {
        $payments = Payment::query()
            ->with('user', 'creditPackage', 'guestInvestigation')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->filled('method'), fn ($query) => $query->where('method', $request->query('method')))
            ->when($request->filled('provider'), fn ($query) => $query->where('provider', $request->query('provider')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('created_at', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('created_at', '<=', $request->query('to')))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.payments.index', [
            'payments' => $payments,
            'statuses' => PaymentStatus::cases(),
            'methods' => PaymentMethod::cases(),
        ]);
    }
}
