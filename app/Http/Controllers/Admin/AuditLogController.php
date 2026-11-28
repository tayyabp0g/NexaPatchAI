<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(): View
    {
        $logs = AuditLog::with('user')->latest('id')->paginate(25);

        return view('admin.audit', compact('logs'));
    }
}
