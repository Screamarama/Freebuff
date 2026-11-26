<?php

namespace App\Http\Controllers;

use App\Models\RentalContract;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Agreements that were due back before today and are still open, with how
 * long they are late and what a late fee might come to.
 */
class OverdueReturnsController extends Controller
{
    public function index(Request $request): View
    {
        $today = now()->startOfDay();

        $contracts = RentalContract::query()
            ->with('customer')
            ->withSum('items as units_out', 'quantity')
            ->when(
                $request->user()->role !== 'super_admin',
                fn ($query) => $query->where('business_id', $request->user()->business_id)
            )
            ->whereNotIn('status', ['returned', 'cancelled'])
            ->whereDate('end_date', '<', $today)
            ->orderBy('end_date')
            ->get();

        $rows = $contracts->map(fn (RentalContract $contract) => [
            'contract' => $contract,
            'days_late' => $contract->daysLate($today),
            'daily_rent' => $contract->dailyRent(),
            'fee' => $contract->suggestedLateFee($today),
        ]);

        return view('reports.overdue-returns', [
            'rows' => $rows,
            'count' => $rows->count(),
            'units' => (int) $rows->sum(fn (array $row) => (int) $row['contract']->units_out),
            'fee' => round($rows->sum('fee'), 2),
            'oldest' => (int) $rows->max('days_late'),
        ]);
    }
}
