<?php

namespace App\Http\Controllers;

use App\Models\FleetMaintenance;
use App\Models\Product;
use App\Models\RentalItem;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What each unit has earned against what it has cost: days on hire against
 * the days it has been in service, rental revenue against repair spend and
 * book value, so keep/sell/stop-repairing decisions have figures behind them.
 */
class FleetUtilisationController extends Controller
{
    public function index(Request $request): View
    {
        $business = $request->user()->business;

        abort_unless($business, 403, 'No tenant assigned to your account.');

        $today = now()->startOfDay();

        $units = Product::query()
            ->where('business_id', $business->id)
            ->where('product_type', 'rental')
            ->where(fn ($query) => $query->whereNull('fleet_status')
                ->orWhereNotIn('fleet_status', [Product::FLEET_SERVICE_HOLD, Product::FLEET_DISPOSED]))
            ->orderBy('equipment_number')
            ->get();

        $itemsByUnit = RentalItem::query()
            ->whereIn('product_id', $units->pluck('id'))
            ->whereHas('contract', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->with(['contract:id,start_date,end_date,actual_return_date,status'])
            ->get(['id', 'product_id', 'contract_id', 'subtotal'])
            ->groupBy('product_id');

        $maintenanceByUnit = FleetMaintenance::query()
            ->whereIn('product_id', $units->pluck('id'))
            ->get()
            ->groupBy('product_id');

        $rows = $units->map(function (Product $unit) use ($itemsByUnit, $maintenanceByUnit, $today) {
            $since = ($unit->depreciation_started_on ?? $unit->purchase_date)?->copy()->startOfDay()
                ?? $unit->created_at?->copy()->startOfDay();
            $daysInService = $since ? max(0, (int) $since->diffInDays($today)) : 0;

            $hireDays = 0;
            $revenue = 0.0;

            foreach ($itemsByUnit->get($unit->id, collect()) as $item) {
                $contract = $item->contract;

                if (! $contract?->start_date) {
                    continue;
                }

                $revenue += (float) $item->subtotal;

                $from = $contract->start_date->copy()->startOfDay();
                if ($since && $from->lt($since)) {
                    $from = $since->copy();
                }

                // A unit out on an open agreement is on hire up to today, even
                // if its booked end date is still ahead.
                $to = ($contract->actual_return_date ?? $today)->copy()->startOfDay();
                if ($to->gt($today)) {
                    $to = $today->copy();
                }

                $hireDays += max(0, (int) $from->diffInDays($to));
            }

            $hireDays = min($hireDays, $daysInService);

            $downtime = 0;
            $maintenanceSpend = 0.0;

            foreach ($maintenanceByUnit->get($unit->id, collect()) as $stint) {
                if (! $stint->started_on) {
                    continue;
                }

                $from = $stint->started_on->copy()->startOfDay();
                if ($since && $from->lt($since)) {
                    $from = $since->copy();
                }

                $to = ($stint->completed_on ?? $today)->copy()->startOfDay();
                if ($to->gt($today)) {
                    $to = $today->copy();
                }

                $downtime += max(0, (int) $from->diffInDays($to));

                // Only completed jobs are spend; an open estimate is not money
                // out of the door yet.
                if ($stint->completed_on !== null) {
                    $maintenanceSpend += (float) ($stint->cost ?? 0);
                }
            }

            $downtime = min($downtime, max(0, $daysInService - $hireDays));
            $available = max(0, $daysInService - $hireDays - $downtime);
            $utilisation = $daysInService > 0 ? (int) round($hireDays / $daysInService * 100) : 0;
            $contribution = round($revenue - $maintenanceSpend, 2);
            $cost = (float) $unit->cost_price;
            $returnOnCost = $cost > 0 ? round($contribution / $cost * 100, 1) : null;

            $signal = match (true) {
                // No history to judge yet: a unit that has already been hired is
                // judged on what it has done, however recently it arrived.
                $daysInService < 30 && $hireDays === 0 => 'Too new',
                $maintenanceSpend > 0 && $maintenanceSpend >= $revenue => 'Stop repairing',
                $utilisation < 25 => 'Low use',
                $utilisation >= 60 && $contribution > 0 => 'Strong',
                default => 'Watch',
            };

            return [
                'unit' => $unit,
                'since' => $since,
                'days_in_service' => $daysInService,
                'hire_days' => $hireDays,
                'downtime' => $downtime,
                'available' => $available,
                'utilisation' => $utilisation,
                'revenue' => round($revenue, 2),
                'maintenance' => round($maintenanceSpend, 2),
                'contribution' => $contribution,
                'book' => $unit->bookValue(),
                'return_on_cost' => $returnOnCost,
                'signal' => $signal,
            ];
        })->sortBy('utilisation')->values();

        $total = [
            'days_in_service' => (int) $rows->sum('days_in_service'),
            'hire_days' => (int) $rows->sum('hire_days'),
            'downtime' => (int) $rows->sum('downtime'),
            'available' => (int) $rows->sum('available'),
            'revenue' => round($rows->sum('revenue'), 2),
            'maintenance' => round($rows->sum('maintenance'), 2),
            'contribution' => round($rows->sum('contribution'), 2),
            'utilisation' => $rows->sum('days_in_service') > 0
                ? (int) round($rows->sum('hire_days') / $rows->sum('days_in_service') * 100)
                : 0,
        ];

        return view('reports.fleet-utilisation', [
            'rows' => $rows,
            'total' => $total,
            'count' => $rows->count(),
            'currency' => $business->currency,
        ]);
    }
}
