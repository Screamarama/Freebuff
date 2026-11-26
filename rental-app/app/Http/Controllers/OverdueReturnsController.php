<?php

namespace App\Http\Controllers;

use App\Models\RentalContract;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Agreements that were due back before today and are still open, with how
 * long they are late and what a late fee might come to.
 */
class OverdueReturnsController extends Controller
{
    public function index(Request $request): View
    {
        return view('reports.overdue-returns', $this->board($request));
    }

    public function export(Request $request): StreamedResponse
    {
        $data = $this->board($request);

        $filename = 'overdue-returns-'.now()->format('Y-m-d').'.csv';

        return $this->csv($filename, function ($file) use ($data) {
            fputcsv($file, [
                'Agreement', 'Customer', 'Phone', 'Due back', 'Days late',
                'Units', 'Daily rent', 'Suggested late fee', 'Currency',
            ]);

            $amount = fn (?float $value) => $value === null ? '' : number_format($value, 2, '.', '');

            foreach ($data['rows'] as $row) {
                $contract = $row['contract'];

                fputcsv($file, Csv::row([
                    $contract->contract_number,
                    $contract->customer?->full_name,
                    $contract->customer?->phone,
                    $contract->end_date?->toDateString(),
                    $row['days_late'],
                    (int) $contract->units_out,
                    $amount($row['daily_rent']),
                    $amount($row['fee']),
                    $data['currency'],
                ]));
            }

            // The same totals the page shows, so the file adds up on its own.
            fputcsv($file, []);
            fputcsv($file, Csv::row([
                'Overdue total',
                $data['count'].' '.($data['count'] === 1 ? 'agreement' : 'agreements'),
                '', '', '', $data['units'], '', $amount($data['fee']), $data['currency'],
            ]));
        });
    }

    /**
     * The rows and totals the page and the download share.
     *
     * @return array<string, mixed>
     */
    private function board(Request $request): array
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

        return [
            'rows' => $rows,
            'count' => $rows->count(),
            'units' => (int) $rows->sum(fn (array $row) => (int) $row['contract']->units_out),
            'fee' => round($rows->sum('fee'), 2),
            'oldest' => (int) $rows->max('days_late'),
            'currency' => $request->user()->business?->currency,
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
