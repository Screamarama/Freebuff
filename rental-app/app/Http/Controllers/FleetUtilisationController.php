<?php

namespace App\Http\Controllers;

use App\Models\FleetMaintenance;
use App\Models\Product;
use App\Models\RentalItem;
use App\Services\FleetUtilisation;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What each unit has earned against what it has cost: days on hire against
 * the days it has been in service, rental revenue against repair spend and
 * book value, so keep/sell/stop-repairing decisions have figures behind them.
 */
class FleetUtilisationController extends Controller
{
    public function index(Request $request, FleetUtilisation $utilisation): View
    {
        return view('reports.fleet-utilisation', $this->report($request, $utilisation));
    }

    public function export(Request $request, FleetUtilisation $utilisation): StreamedResponse
    {
        $data = $this->report($request, $utilisation);

        $filename = 'fleet-utilisation-'.now()->format('Y-m-d').'.csv';

        return $this->csv($filename, function ($file) use ($data) {
            fputcsv($file, [
                'Equipment number', 'Model', 'In service', 'Days in service', 'Days on hire',
                'Maintenance days', 'Available days', 'Utilisation %', 'Rental revenue',
                'Maintenance spend', 'Contribution', 'Book value', 'Return on cost %',
                'Signal', 'Currency',
            ]);

            $amount = fn (?float $value) => $value === null ? '' : number_format($value, 2, '.', '');

            foreach ($data['rows'] as $row) {
                fputcsv($file, Csv::row([
                    $row['unit']->equipment_number,
                    $row['unit']->name,
                    $row['since']?->toDateString(),
                    $row['days_in_service'],
                    $row['hire_days'],
                    $row['downtime'],
                    $row['available'],
                    $row['utilisation'],
                    $amount($row['revenue']),
                    $amount($row['maintenance']),
                    $amount($row['contribution']),
                    $amount($row['book']),
                    $row['return_on_cost'] === null ? '' : number_format($row['return_on_cost'], 1, '.', ''),
                    $row['signal'],
                    $data['currency'],
                ]));
            }

            fputcsv($file, []);
            fputcsv($file, Csv::row([
                'Fleet total',
                $data['count'].' '.($data['count'] === 1 ? 'unit' : 'units'),
                '',
                $data['total']['days_in_service'],
                $data['total']['hire_days'],
                $data['total']['downtime'],
                $data['total']['available'],
                $data['total']['utilisation'],
                $amount($data['total']['revenue']),
                $amount($data['total']['maintenance']),
                $amount($data['total']['contribution']),
                '',
                '',
                '',
                $data['currency'],
            ]));
        });
    }

    /**
     * The figures the page and the download share.
     *
     * @return array<string, mixed>
     */
    private function report(Request $request, FleetUtilisation $utilisation): array
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

        $rows = $units
            ->map(fn (Product $unit) => $utilisation->calculate(
                $unit,
                $itemsByUnit->get($unit->id, collect()),
                $maintenanceByUnit->get($unit->id, collect()),
                $today
            ))
            ->sortBy('utilisation')
            ->values();

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

        return [
            'rows' => $rows,
            'total' => $total,
            'count' => $rows->count(),
            'currency' => $business->currency,
        ];
    }

    private function csv(string $filename, \Closure $write): StreamedResponse
    {
        return response()->streamDownload(function () use ($write) {
            $file = fopen('php://output', 'w');

            // The BOM is what makes Excel read the file as UTF-8.
            fwrite($file, "\xEF\xBB\xBF");

            $write($file);

            fclose($file);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
