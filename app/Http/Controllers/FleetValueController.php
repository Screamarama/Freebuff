<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Product;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What the rental fleet is worth on the books today: cost, depreciation to
 * date, book value, and the units that cannot be valued or can no longer
 * depreciate. Service-hold units are not fleet yet and are left out until
 * they are committed.
 */
class FleetValueController extends Controller
{
    public function index(Request $request): View
    {
        return view('reports.fleet-value', $this->register($request->user()->business));
    }

    public function export(Request $request): StreamedResponse
    {
        $data = $this->register($request->user()->business);

        $filename = 'fleet-value-'.now()->format('Y-m-d').'.csv';

        return $this->csv($filename, function ($file) use ($data) {
            fputcsv($file, [
                'Equipment number', 'Catalogue model', 'Serial number', 'Status',
                'Cost', 'Salvage', 'Useful life (months)', 'Depreciation start',
                'Months depreciated', 'Book value', 'Monthly depreciation',
                'Depreciation to date', 'Currency', 'Note',
            ]);

            // Amounts go out as plain numbers; an absent figure is an empty cell.
            $amount = fn (?float $value) => $value === null ? '' : number_format($value, 2, '.', '');

            foreach ($data['rows'] as $row) {
                fputcsv($file, Csv::row([
                    $row['unit']->equipment_number,
                    $row['model'],
                    $row['unit']->serial_number,
                    $row['status'],
                    $amount($row['cost']),
                    $amount($row['salvage']),
                    $row['life'] ?: '',
                    $row['started']?->toDateString(),
                    $row['elapsed'] ?? '',
                    $amount($row['book']),
                    $amount($row['monthly']),
                    $amount($row['depreciated']),
                    $data['currency'],
                    implode('; ', $row['issues']),
                ]));
            }

            // The per-model breakdown the page shows, so the file totals without
            // anyone re-adding the unit rows.
            fputcsv($file, []);
            fputcsv($file, ['Catalogue model', 'Units', 'Cost', 'Book value', 'Monthly depreciation', 'Depreciation to date', 'Units needing attention']);

            foreach ($data['models'] as $model) {
                fputcsv($file, Csv::row([
                    $model['label'],
                    $model['units'],
                    $amount($model['cost']),
                    $amount($model['book']),
                    $amount($model['monthly']),
                    $amount($model['depreciated']),
                    $model['attention'],
                ]));
            }

            fputcsv($file, Csv::row([
                'Fleet total',
                $data['count'],
                $amount($data['cost']),
                $amount($data['book']),
                $amount($data['monthly']),
                $amount($data['depreciated']),
                $data['attention'],
            ]));
        });
    }

    /**
     * The figures the page and the download share, for every rental unit that
     * is in the fleet (service hold excluded) and still on the books.
     *
     * @return array<string, mixed>
     */
    private function register(Business $business): array
    {
        $rows = Product::query()
            ->where('business_id', $business->id)
            ->where('product_type', 'rental')
            ->where(fn ($query) => $query->whereNull('fleet_status')
                ->orWhereNotIn('fleet_status', [Product::FLEET_SERVICE_HOLD, Product::FLEET_DISPOSED]))
            ->with('equipmentModel')
            ->orderBy('equipment_number')
            ->get()
            ->map(fn (Product $unit) => $this->row($unit))
            ->values();

        $valued = $rows->whereNotNull('book');

        $models = $rows
            // Without a catalogue model there is nothing to group by, so each
            // such unit keeps its own row rather than borrowing another's name.
            ->groupBy(fn (array $row) => $row['unit']->equipment_model_id ?? 'unit-'.$row['unit']->id)
            ->map(function (Collection $group) {
                return [
                    'label' => $group->first()['model'],
                    'units' => $group->count(),
                    'cost' => round((float) $group->sum('cost'), 2),
                    'book' => round((float) $group->whereNotNull('book')->sum('book'), 2),
                    'monthly' => round((float) $group->whereNotNull('monthly')->sum('monthly'), 2),
                    'depreciated' => round((float) $group->whereNotNull('depreciated')->sum('depreciated'), 2),
                    'attention' => $group->filter(fn (array $row) => $row['issues'] !== [])->count(),
                ];
            })
            ->sortBy('label')
            ->values();

        return [
            'rows' => $rows,
            'models' => $models,
            'count' => $rows->count(),
            'cost' => round((float) $rows->sum('cost'), 2),
            'book' => round((float) $valued->sum('book'), 2),
            'monthly' => round((float) $rows->whereNotNull('monthly')->sum('monthly'), 2),
            'depreciated' => round((float) $rows->whereNotNull('depreciated')->sum('depreciated'), 2),
            'attention' => $rows->filter(fn (array $row) => $row['issues'] !== [])->count(),
            'attentionRows' => $rows->filter(fn (array $row) => $row['issues'] !== [])->values(),
            'unvalued' => $rows->count() - $valued->count(),
            'atSalvage' => $rows->filter(fn (array $row) => $row['at_salvage'])->values(),
            'fullyDepreciated' => $rows->filter(fn (array $row) => $row['fully_depreciated'])->values(),
            'currency' => $business->currency,
        ];
    }

    /**
     * One unit's figures. A missing cost, life or start date is named rather
     * than shown as a confident zero.
     *
     * @return array<string, mixed>
     */
    private function row(Product $unit): array
    {
        $cost = (float) ($unit->cost_price ?? 0);
        $salvage = min((float) ($unit->salvage_value ?? 0), $cost);
        $life = (int) ($unit->useful_life_months ?? 0);
        $started = $unit->depreciation_started_on ?? $unit->purchase_date;

        $monthly = $unit->monthlyDepreciation();
        $book = $unit->bookValue();

        $issues = [];

        if ($cost <= 0) {
            $issues[] = 'No purchase cost';
        }

        if ($life <= 0) {
            $issues[] = 'No useful life';
        }

        if ($started === null) {
            $issues[] = 'No depreciation start';
        }

        $elapsed = $started !== null
            ? (int) floor(max(0, $started->copy()->startOfDay()->diffInMonths(now()->startOfDay())))
            : null;

        $fullyDepreciated = $elapsed !== null && $life > 0 && $elapsed >= $life;
        $atSalvage = $book !== null && abs($book - $salvage) < 0.005;

        return [
            'unit' => $unit,
            'model' => $unit->equipmentModel?->label() ?: ($unit->name ?: '—'),
            'cost' => round($cost, 2),
            'salvage' => round($salvage, 2),
            'life' => $life,
            'started' => $started,
            'elapsed' => $elapsed,
            'book' => $book,
            'monthly' => $monthly,
            'depreciated' => $book === null ? null : round(max(0, $cost - $book), 2),
            'fully_depreciated' => $fullyDepreciated,
            'at_salvage' => $atSalvage,
            'issues' => $issues,
            'status' => $this->status($issues, $fullyDepreciated, $atSalvage),
        ];
    }

    private function status(array $issues, bool $fullyDepreciated, bool $atSalvage): string
    {
        if ($issues !== []) {
            return 'Needs attention';
        }

        if ($fullyDepreciated) {
            return 'Fully depreciated';
        }

        return $atSalvage ? 'At salvage' : 'Depreciating';
    }

    /**
     * Stream a CSV the way every download here does: UTF-8 BOM, the rows the
     * caller writes, and the same response headers.
     */
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
