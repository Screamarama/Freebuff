<?php

namespace App\Services;

use App\Models\FleetMaintenance;
use App\Models\Product;
use App\Models\RentalItem;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * One unit's days on hire, downtime, revenue and repair spend. The fleet
 * report and the unit page both come through here so their figures cannot
 * drift apart.
 */
class FleetUtilisation
{
    /**
     * @return array<string, mixed>
     */
    public function forUnit(Product $unit, ?CarbonInterface $today = null): array
    {
        $items = RentalItem::query()
            ->where('product_id', $unit->id)
            ->whereHas('contract', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->with(['contract:id,start_date,end_date,actual_return_date,status'])
            ->get(['id', 'product_id', 'contract_id', 'subtotal']);

        $stints = FleetMaintenance::query()
            ->where('product_id', $unit->id)
            ->get();

        return $this->calculate($unit, $items, $stints, $today);
    }

    /**
     * @param  Collection<int, RentalItem>  $items
     * @param  Collection<int, FleetMaintenance>  $stints
     * @return array<string, mixed>
     */
    public function calculate(Product $unit, Collection $items, Collection $stints, ?CarbonInterface $today = null): array
    {
        $today = Carbon::parse($today ?? now())->startOfDay();

        $since = ($unit->depreciation_started_on ?? $unit->purchase_date)?->copy()->startOfDay()
            ?? $unit->created_at?->copy()->startOfDay();
        $daysInService = $since ? max(0, (int) $since->diffInDays($today)) : 0;

        $hireDays = 0;
        $revenue = 0.0;

        foreach ($items as $item) {
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

        foreach ($stints as $stint) {
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
    }
}
