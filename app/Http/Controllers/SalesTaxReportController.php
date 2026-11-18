<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\RentalContract;
use App\Models\TaxFiling;
use App\Support\Csv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What tax was billed, by month and by agreement. Figures come from the
 * agreement's own snapshot (tax_amount and the label captured at billing), so
 * the report always agrees with the statements customers were given.
 */
class SalesTaxReportController extends Controller
{
    public function index(Request $request): View
    {
        [$from, $to] = $this->filters($request);

        return view('reports.sales-tax', $this->report($request->user()->business, $from, $to));
    }

    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->filters($request);

        $data = $this->report($request->user()->business, $from, $to);

        $filename = 'sales-tax-'.($data['from'] ?? 'start').'-'.($data['to'] ?? 'latest').'.csv';

        return $this->csv($filename, function ($file) use ($data) {
            fputcsv($file, [
                'Month', 'Date', 'Agreement', 'Customer', 'Tax', 'Rate %',
                'Currency', 'Billed tax', 'Adjustment', 'Exempt sales', 'Gross sales', 'Taxable sales',
                'Figures', 'Filed', 'Certificate', 'Certificate expires', 'Certificate reason',
            ]);

            // Amounts go out as plain numbers; an absent figure is an empty cell.
            $amount = fn (float $value) => $value != 0 ? number_format($value, 2, '.', '') : '';

            // This file is always today's figures, so a filed month says so and
            // names the filing it may differ from.
            $filings = $data['periods']->pluck('filing', 'period');
            $filedAt = fn (Carbon $date) => ($filings[$date->format('Y-m')] ?? null)?->filed_at->format('Y-m-d');

            foreach ($data['rows'] as $row) {
                fputcsv($file, Csv::row([
                    $row['date']->format('F Y'),
                    $row['date']->format('Y-m-d'),
                    $row['contract']->contract_number,
                    $row['contract']->customer?->full_name,
                    $row['label'],
                    $row['rate'],
                    $row['currency'],
                    $amount($row['billed']),
                    $amount($row['adjustment']),
                    '',
                    '',
                    '',
                    'Current',
                    $filedAt($row['date']),
                    '',
                    '',
                    '',
                ]));
            }

            foreach ($data['exemptSales'] as $row) {
                fputcsv($file, Csv::row([
                    $row['date']->format('F Y'),
                    $row['date']->format('Y-m-d'),
                    $row['contract']->contract_number,
                    $row['contract']->customer?->full_name,
                    'Exempt sale',
                    '',
                    $row['currency'],
                    '',
                    '',
                    $amount($row['sales']),
                    '',
                    '',
                    'Current',
                    $filedAt($row['date']),
                    $row['evidence']['number'],
                    $row['evidence']['expires_on']?->toDateString(),
                    $row['evidence']['reason'],
                ]));
            }

            // One summary line per month, so the file states the figures a
            // return asks for without anyone re-adding the rows.
            foreach ($data['periods'] as $period) {
                // A month may hold adjustments without sales, so take the
                // currencies from either figure.
                $currencies = array_keys($period['summary']['gross'] + $period['summary']['tax']);

                foreach ($currencies as $currency) {
                    fputcsv($file, Csv::row([
                        $period['month'],
                        '',
                        'Month summary',
                        '',
                        '',
                        '',
                        $currency,
                        $amount($period['summary']['tax'][$currency] ?? 0),
                        '',
                        $amount($period['summary']['exempt'][$currency] ?? 0),
                        $amount($period['summary']['gross'][$currency] ?? 0),
                        $amount($period['summary']['taxable'][$currency] ?? 0),
                        'Current',
                        $period['filing']?->filed_at->format('Y-m-d'),
                        '',
                        '',
                        '',
                    ]));
                }
            }

        });
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

    /**
     * A filed month as it was filed: the snapshot itself, not today's figures.
     */
    public function exportFiling(Request $request, TaxFiling $filing): StreamedResponse
    {
        abort_unless($filing->business_id === $request->user()->business_id, 404);

        $snapshot = $filing->snapshot;
        $month = Carbon::createFromFormat('Y-m', $filing->period)->format('F Y');
        $filedAt = $filing->filed_at->format('Y-m-d');
        $filedBy = $filing->filedBy?->name;

        return $this->csv('sales-tax-filed-'.$filing->period.'.csv', function ($file) use ($snapshot, $month, $filedAt, $filedBy) {
            fputcsv($file, ['Month', 'Filed at', 'Filed by', 'Item', 'Currency', 'Amount', 'Figures']);

            $amount = fn (float $value) => $value != 0 ? number_format($value, 2, '.', '') : '';

            foreach ($this->summaryLabels() as $field => $label) {
                foreach ($snapshot['summary'][$field] ?? [] as $currency => $value) {
                    fputcsv($file, Csv::row([
                        $month, $filedAt, $filedBy, $label, $currency, $amount((float) $value), 'Filed',
                    ]));
                }
            }

            foreach ($snapshot['entries'] ?? [] as $entry) {
                fputcsv($file, Csv::row([
                    $month, $filedAt, $filedBy, $entry['label'], $entry['currency'],
                    $amount((float) $entry['amount']), 'Filed',
                ]));
            }

        });
    }

    /**
     * What moved in a filed month since it was filed, as a file the correction
     * can be filed from: summary deltas plus every added, re-amounted or
     * removed row.
     */
    public function exportAmendment(Request $request, TaxFiling $filing): StreamedResponse
    {
        abort_unless($filing->business_id === $request->user()->business_id, 404);

        $month = Carbon::createFromFormat('Y-m', $filing->period);
        $data = $this->report(
            Business::findOrFail($filing->business_id),
            $month->copy()->startOfMonth()->toDateString(),
            $month->copy()->endOfMonth()->toDateString()
        );

        // A month that no longer reports anything still has its filed version:
        // an empty current month makes every filed row show as removed.
        $period = $data['periods']->firstWhere('period', $filing->period) ?? [
            'entries' => collect(),
            'summary' => ['gross' => [], 'taxable' => [], 'exempt' => [], 'tax' => []],
        ];

        $changes = $this->changesSince($filing, $period);
        $monthName = $month->format('F Y');
        $filedAt = $filing->filed_at->format('Y-m-d');
        $filedBy = $filing->filedBy?->name;

        return $this->csv('sales-tax-amendment-'.$filing->period.'.csv', function ($file) use ($changes, $monthName, $filedAt, $filedBy) {
            fputcsv($file, ['Month', 'Filed at', 'Filed by', 'Change', 'Item', 'Currency', 'Amount', 'Was', 'Figures']);

            $amount = fn (float $value) => $value != 0 ? number_format($value, 2, '.', '') : '';

            foreach ($this->summaryLabels() as $field => $label) {
                foreach ($changes['summary'][$field] ?? [] as $currency => $delta) {
                    fputcsv($file, Csv::row([
                        $monthName, $filedAt, $filedBy, 'Summary', $label, $currency, $amount($delta), '', 'Amendment',
                    ]));
                }
            }

            foreach ($changes['lines'] as $entry) {
                fputcsv($file, Csv::row([
                    $monthName,
                    $filedAt,
                    $filedBy,
                    $entry['removed'] ? 'Removed' : ($entry['was'] !== null ? 'Re-amounted' : 'Added'),
                    $entry['label'],
                    $entry['currency'],
                    $amount((float) $entry['amount']),
                    $entry['was'] !== null ? $amount((float) $entry['was']) : '',
                    'Amendment',
                ]));
            }
        });
    }

    /** The return's summary lines, in the order the report and files list them. */
    private function summaryLabels(): array
    {
        return ['gross' => 'Gross sales', 'taxable' => 'Taxable sales', 'exempt' => 'Exempt sales', 'tax' => 'Tax charged'];
    }

    /**
     * Everything the page and the download share: the rows, grouped by month,
     * and their totals per currency.
     *
     * @return array<string, mixed>
     */
    /**
     * Mark a month as filed: snapshot its figures so anything added or moved
     * afterwards is shown as a change against the filed version.
     */
    public function storeFiling(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
        ]);

        $business = $request->user()->business;
        $month = Carbon::createFromFormat('Y-m', $validated['period']);

        $data = $this->report(
            $business,
            $month->copy()->startOfMonth()->toDateString(),
            $month->copy()->endOfMonth()->toDateString()
        );

        $period = $data['periods']->firstWhere('period', $validated['period']);

        if ($period === null) {
            return back()->withErrors(['period' => 'That month has nothing to file.']);
        }

        // A return states a closed period: charges can still arrive until the
        // month ends, so filing early would only have to be amended later.
        if (! $period['closed']) {
            return back()->withErrors([
                'period' => $period['month'].' has not ended yet. File it after '.$period['closes'].'.',
            ]);
        }

        $previous = TaxFiling::query()
            ->where('business_id', $business->id)
            ->where('period', $validated['period'])
            ->current()
            ->first();

        $filing = DB::transaction(function () use ($business, $validated, $period, $previous, $request) {
            // The replaced version is kept, not overwritten, so the figures it
            // reported stay downloadable.
            $previous?->update(['superseded_at' => now()]);

            return TaxFiling::create([
                'business_id' => $business->id,
                'period' => $validated['period'],
                'filed_at' => now(),
                'filed_by_id' => $request->user()->id,
                'snapshot' => [
                    'summary' => $period['summary'],
                    'entries' => $period['entries']->all(),
                ],
            ]);
        });

        AuditLog::record(
            $previous !== null ? 'tax.refiled' : 'tax.filed',
            ['name' => $period['month'], 'period' => $validated['period']],
            $request->user(),
            $business->id,
            $filing
        );

        return redirect()
            ->route('sales-tax.index')
            ->with('success', $period['month'].' marked as filed.');
    }

    /** The from/to date filters, as the request gave them. */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return [$validated['from'] ?? null, $validated['to'] ?? null];
    }

    private function report(Business $business, ?string $fromInput, ?string $toInput): array
    {
        $from = $fromInput !== null ? Carbon::parse($fromInput)->startOfDay() : null;
        $to = $toInput !== null ? Carbon::parse($toInput)->endOfDay() : null;

        $contracts = RentalContract::query()
            ->where('business_id', $business->id)
            // Either billed then edited down, never billed at all, or exempt.
            ->where(fn ($query) => $query->where('tax_amount', '>', 0)
                ->orWhere('charged_tax_amount', '>', 0)
                ->orWhere('tax_exempt', true))
            ->with([
                'customer',
                'taxAdjustments',
                'payments' => fn ($query) => $query->where('kind', 'payment')->effective(),
            ])
            ->get();

        // Billed tax belongs to the month it was billed in; a later adjustment
        // or a reversal belongs to the month it happened, so a closed period
        // is never rewritten. A cash agreement belongs to the month it was paid
        // or completed, not the month it happened to be written.
        $rows = $contracts
            ->flatMap(function (RentalContract $contract) use ($business) {
                $common = [
                    'contract' => $contract,
                    'label' => $contract->charged_tax_label ?? $business->taxLabel(),
                    'currency' => (string) ($contract->currency ?? $business->currency),
                    'rate' => rtrim(rtrim((string) $contract->tax_rate, '0'), '.'),
                ];

                $billed = $this->taxBilled($contract);
                $reversed = $contract->charge_reversed_at !== null;

                $rows = [];

                if ($billed > 0) {
                    $rows[] = [
                        ...$common,
                        'key' => 'billed:'.$contract->id,
                        'date' => $this->reportDate($contract),
                        'billed' => $billed,
                        'adjustment' => 0.0,
                    ];
                }

                $adjusted = 0.0;

                foreach ($contract->taxAdjustments as $entry) {
                    $amount = round((float) $entry->amount, 2);
                    $adjusted += $amount;

                    $rows[] = [
                        ...$common,
                        'key' => 'adjustment:'.$entry->id,
                        'date' => $entry->adjusted_at,
                        'billed' => 0.0,
                        'adjustment' => $amount,
                    ];
                }

                // Reversing credits the tax as it stood then: the billed figure
                // plus the adjustments already made, each of which keeps its own
                // month rather than being hidden by the reversal.
                if ($reversed && $billed + $adjusted != 0) {
                    $rows[] = [
                        ...$common,
                        'key' => 'reversal:'.$contract->id,
                        'date' => $contract->charge_reversed_at,
                        'billed' => 0.0,
                        'adjustment' => -round($billed + $adjusted, 2),
                    ];
                }

                return $rows;
            })
            ->filter(fn (array $row) => ($from === null || $row['date']->gte($from))
                && ($to === null || $row['date']->lte($to)))
            ->sortByDesc(fn (array $row) => $row['date'])
            ->values();

        // Every sale in the period, valued and marked as taxed or exempt, so a
        // filing can state gross, taxable and exempt figures. A reversed sale
        // is given back in the month it was reversed, so the month it was sold
        // in keeps the sale it reported rather than losing it retroactively.
        $sales = $contracts
            ->flatMap(function (RentalContract $contract) use ($business) {
                $billed = $this->taxBilled($contract);
                $sale = $contract->salesBasis();
                $exempt = $contract->tax_exempt && $billed == 0;

                $common = [
                    'contract' => $contract,
                    'currency' => (string) ($contract->currency ?? $business->currency),
                    'taxed' => $billed > 0,
                    // Tax actually frozen onto a statement rules the sale out of
                    // the exempt figures: it is reported as billed tax.
                    'exempt' => $exempt,
                    // The certificate the sale relied on, frozen at billing.
                    'evidence' => $contract->taxExemptionEvidence(),
                ];

                $rows = [[
                    ...$common,
                    'key' => ($exempt ? 'exempt:' : 'sale:').$contract->id,
                    'date' => $this->reportDate($contract),
                    'sales' => $sale,
                    'credit' => false,
                ]];

                if ($contract->charge_reversed_at !== null) {
                    $rows[] = [
                        ...$common,
                        'key' => ($exempt ? 'exempt-reversal:' : 'sale-reversal:').$contract->id,
                        'date' => $contract->charge_reversed_at,
                        'sales' => -$sale,
                        'credit' => true,
                    ];
                }

                return $rows;
            })
            ->filter(fn (array $row) => $row['sales'] != 0
                && ($from === null || $row['date']->gte($from))
                && ($to === null || $row['date']->lte($to)))
            ->sortByDesc(fn (array $row) => $row['date'])
            ->values();

        $exemptSales = $sales->filter(fn (array $row) => $row['exempt'])->values();

        $taxMonths = $rows->groupBy(fn (array $row) => $row['date']->format('Y-m'));
        $salesMonths = $sales->groupBy(fn (array $row) => $row['date']->format('Y-m'));

        $periods = $taxMonths->keys()
            ->merge($salesMonths->keys())
            ->unique()
            ->sortDesc()
            ->map(function (string $month) use ($taxMonths, $salesMonths) {
                $monthRows = $taxMonths->get($month, collect());
                $monthSales = $salesMonths->get($month, collect());
                $billedTotals = $this->totalsByCurrency($monthRows, 'billed');
                $adjustmentTotals = $this->totalsByCurrency($monthRows, 'adjustment');

                $exemptRows = $monthSales->filter(fn (array $row) => $row['exempt'])->values();

                $monthStart = Carbon::createFromFormat('Y-m', $month);
                $monthEnd = $monthStart->copy()->endOfMonth();

                return [
                    'period' => $month,
                    'month' => $monthStart->format('F Y'),
                    // Only a closed month can be filed; an open one says when it
                    // will be ready instead of offering the button.
                    'closed' => $monthEnd->isPast(),
                    'closes' => $monthEnd->format('M j, Y'),
                    'rows' => $monthRows,
                    'agreements' => $monthRows->pluck('contract')
                        ->merge($monthSales->pluck('contract'))
                        ->unique('id')
                        ->count(),
                    'exemptRows' => $exemptRows,
                    'summary' => $this->summaryTotals($monthSales, $billedTotals, $adjustmentTotals),
                    'entries' => $monthRows->mapWithKeys(fn (array $row) => [$row['key'] => [
                        'label' => $row['contract']->contract_number.' · '.match (true) {
                            str_starts_with($row['key'], 'adjustment:') => 'Adjustment',
                            str_starts_with($row['key'], 'reversal:') => 'Reversal',
                            default => $row['label'],
                        },
                        'amount' => $row['billed'] > 0 ? $row['billed'] : $row['adjustment'],
                        'currency' => $row['currency'],
                    ]])->merge($exemptRows->mapWithKeys(fn (array $row) => [$row['key'] => [
                        'label' => $row['contract']->contract_number.' · '.($row['credit'] ? 'Exempt sale reversal' : 'Exempt sale')
                            .$this->exemptionNote($row['evidence']),
                        'amount' => $row['sales'],
                        'currency' => $row['currency'],
                    ]])),
                ];
            })
            ->values();

        $filings = TaxFiling::query()
            ->where('business_id', $business->id)
            ->whereIn('period', $periods->pluck('period'))
            ->with('filedBy')
            ->orderByDesc('filed_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('period');

        $periods = $periods->map(function (array $period) use ($filings) {
            $versions = $filings->get($period['period'], collect());
            $filing = $versions->firstWhere('superseded_at', null) ?? $versions->first();

            $period['filing'] = $filing;
            // Earlier versions stay reachable, with the figures they reported.
            $period['history'] = $versions->reject(fn (TaxFiling $version) => $version->is($filing))->values();
            $period['changes'] = $filing ? $this->changesSince($filing, $period) : null;

            return $period;
        });

        $billedTotals = $this->totalsByCurrency($rows, 'billed');
        $adjustmentTotals = $this->totalsByCurrency($rows, 'adjustment');

        return [
            'periods' => $periods,
            'rows' => $rows,
            'exemptSales' => $exemptSales,
            'billedTotals' => $billedTotals,
            'adjustmentTotals' => $adjustmentTotals,
            'summary' => $this->summaryTotals($sales, $billedTotals, $adjustmentTotals),
            'count' => $rows->pluck('contract')
                ->merge($sales->pluck('contract'))
                ->unique('id')
                ->count(),
            'from' => $fromInput,
            'to' => $toInput,
        ];
    }

    /**
     * What moved in a month after it was filed: rows added, re-amounted or no
     * longer reported, and how the summary figures changed.
     *
     * @return array{lines: \Illuminate\Support\Collection, summary: array<string, array<string, float>>, changed: bool}
     */
    /** The certificate behind an exempt sale, as one readable note. */
    private function exemptionNote(array $evidence): string
    {
        $parts = array_filter([
            $evidence['number'] ? 'certificate '.$evidence['number'] : null,
            $evidence['expires_on'] ? 'expires '.$evidence['expires_on']->toDateString() : null,
            $evidence['reason'] ?: null,
        ]);

        return $parts === [] ? '' : ' ('.implode(', ', $parts).')';
    }

    private function changesSince(TaxFiling $filing, array $period): array
    {
        $filedEntries = collect($filing->snapshot['entries'] ?? []);

        // One list of lines either way, so the page renders changes once.
        $lines = collect();

        foreach ($period['entries'] as $key => $entry) {
            $before = $filedEntries->get($key);

            if ($before === null || abs((float) $before['amount'] - $entry['amount']) > 0.004) {
                $lines->put($key, [...$entry, 'was' => $before['amount'] ?? null, 'removed' => false]);
            }
        }

        foreach ($filedEntries as $key => $entry) {
            if (! $period['entries']->has($key)) {
                $lines->put($key, [...$entry, 'was' => null, 'removed' => true]);
            }
        }

        $summary = [];

        foreach ($period['summary'] as $field => $totals) {
            $before = $filing->snapshot['summary'][$field] ?? [];

            foreach (array_keys($totals + $before) as $currency) {
                $delta = round((float) ($totals[$currency] ?? 0) - (float) ($before[$currency] ?? 0), 2);

                if ($delta != 0) {
                    $summary[$field][$currency] = $delta;
                }
            }
        }

        return [
            'lines' => $lines,
            'summary' => $summary,
            'changed' => $lines->isNotEmpty() || $summary !== [],
        ];
    }

    /**
     * What a return asks for: gross sales, how much of it was taxable and how
     * much exempt, and the tax charged on it (billed plus adjustments).
     *
     * @return array<string, array<string, float>>
     */
    private function summaryTotals($sales, array $billedTotals, array $adjustmentTotals): array
    {
        $taxable = $this->totalsByCurrency($sales->filter(fn (array $row) => $row['taxed']), 'sales');
        $exempt = $this->totalsByCurrency($sales->filter(fn (array $row) => $row['exempt']), 'sales');

        return [
            // Every reported sale is taxed or exempt, so gross is their sum and
            // cannot drift from the two figures beside it.
            'gross' => $this->sumTotals($taxable, $exempt),
            'taxable' => $taxable,
            'exempt' => $exempt,
            'tax' => $this->sumTotals($billedTotals, $adjustmentTotals),
        ];
    }

    /**
     * Currency totals added together: two maps, summed per currency.
     *
     * @return array<string, float>
     */
    private function sumTotals(array ...$totals): array
    {
        $summed = [];

        foreach ($totals as $byCurrency) {
            foreach ($byCurrency as $currency => $amount) {
                $summed[$currency] = round(($summed[$currency] ?? 0) + $amount, 2);
            }
        }

        return $summed;
    }

    /**
     * Which month an agreement is reported in: the charge date once billed,
     * otherwise the later of completion and the last receipt it took.
     */
    private function reportDate(RentalContract $contract): Carbon
    {
        if ($contract->charged_to_account_at !== null) {
            return $contract->charged_to_account_at;
        }

        $dates = collect([$contract->payments->max('payment_date'), $contract->actual_return_date])
            ->filter()
            ->map(fn ($date) => Carbon::parse($date))
            ->sortBy(fn (Carbon $date) => $date->getTimestamp());

        return $dates->last() ?? $contract->created_at;
    }

    /**
     * The tax as billed. A billed agreement reports the figure frozen onto its
     * statement, so editing the agreement afterwards cannot rewrite the report;
     * an agreement never billed to an account reports its current tax.
     */
    private function taxBilled(RentalContract $contract): float
    {
        return $contract->charged_to_account_at !== null
            ? round((float) ($contract->charged_tax_amount ?? 0), 2)
            : round((float) $contract->tax_amount, 2);
    }

    /**
     * Tax summed per currency: adding dollars to euros would be a fiction, so
     * each currency gets its own figure instead of one mixed total.
     *
     * @return array<string, float>
     */
    private function totalsByCurrency($rows, string $field): array
    {
        return $rows
            ->groupBy('currency')
            ->map(fn ($group) => round((float) $group->sum($field), 2))
            ->all();
    }

}
