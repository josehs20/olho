<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function index(): View
    {
        return view('admin.audit.index', [
            'audits' => AuditLog::query()->with('user')->latest()->paginate(30),
        ]);
    }
}
