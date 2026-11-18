<?php

namespace App\Http\Controllers;

use App\Services\CustomerAccount;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountsReceivableController extends Controller
{
    public function index(Request $request, CustomerAccount $accounts): View
    {
        $businessId = $request->user()->business_id;

        if (! $businessId) {
            abort(403, 'No tenant assigned to your account.');
        }

        return view('receivables.index', [
            'aging' => $accounts->aging($businessId),
            'buckets' => CustomerAccount::AGING_BUCKETS,
            'currency' => $request->user()->business?->currency,
        ]);
    }
}
