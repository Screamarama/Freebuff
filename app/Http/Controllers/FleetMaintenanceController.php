<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\FleetMaintenance;
use App\Models\Product;
use App\Models\RentalItem;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * A unit away for repair leaves availability but stays on the books. It is not
 * service hold: that is supplier stock waiting to be set up, this is fleet the
 * business already runs. Every stint keeps its reason, vendor, dates, cost and
 * notes so downtime adds up in the unit's record.
 */
class FleetMaintenanceController extends Controller
{
    public function index(Request $request): View
    {
        $business = $request->user()->business;

        $open = FleetMaintenance::query()
            ->where('business_id', $business->id)
            ->whereNull('completed_on')
            ->with(['product.equipmentModel', 'recordedBy'])
            ->orderBy('expected_return_on')
            ->orderBy('started_on')
            ->get();

        $completed = FleetMaintenance::query()
            ->where('business_id', $business->id)
            ->whereNotNull('completed_on')
            ->with(['product.equipmentModel'])
            ->orderByDesc('completed_on')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('maintenance.index', [
            'open' => $open,
            'completed' => $completed,
            'downtime' => (int) $completed->sum(fn (FleetMaintenance $job) => $job->daysOut()),
            'cost' => round((float) $open->sum('cost') + (float) $completed->sum('cost'), 2),
            'currency' => $business->currency,
        ]);
    }

    public function create(Request $request, Product $product): View
    {
        $this->ensureAvailableForMaintenance($request, $product);

        return view('maintenance.create', [
            'product' => $product,
            'previousCost' => $product->maintenances()->latest('started_on')->value('cost'),
        ]);
    }

    public function store(Request $request, Product $product): RedirectResponse
    {
        $this->ensureAvailableForMaintenance($request, $product);

        $data = $request->validate([
            'reason' => ['required', 'max:200'],
            'vendor' => ['nullable', 'max:150'],
            'started_on' => ['required', 'date', 'before_or_equal:today'],
            'expected_return_on' => ['nullable', 'date', 'after_or_equal:started_on'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'max:2000'],
        ]);

        $this->assertNotOnHire($product);

        $job = DB::transaction(function () use ($request, $product, $data) {
            // Out of availability until it comes back, but still on the books.
            $product->update([
                'fleet_status' => Product::FLEET_MAINTENANCE,
                'is_active' => false,
            ]);

            return FleetMaintenance::create([
                'business_id' => $product->business_id,
                'product_id' => $product->id,
                'reason' => $data['reason'],
                'vendor' => $data['vendor'] ?? null,
                'started_on' => Carbon::parse($data['started_on'])->toDateString(),
                'expected_return_on' => $data['expected_return_on'] ?? null,
                'cost' => $data['cost'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by_id' => $request->user()->id,
            ]);
        });

        AuditLog::record('maintenance.started', [
            'equipment_number' => $product->equipment_number,
            'reason' => $job->reason,
            'vendor' => $job->vendor,
            'started_on' => $job->started_on->toDateString(),
            'expected_return_on' => $job->expected_return_on?->toDateString(),
            'cost' => $job->cost,
        ], $request->user(), $product->business_id, $product);

        return redirect()
            ->route('maintenance.index')
            ->with('success', $this->startedMessage($product, $job));
    }

    /** What is being done while it is away: notes can be updated any time. */
    public function updateNotes(Request $request, FleetMaintenance $maintenance): RedirectResponse
    {
        $this->ensureSameTenant($request, $maintenance);

        abort_unless($maintenance->isOpen(), 422, 'This maintenance job has already been completed.');

        $data = $request->validate([
            'notes' => ['nullable', 'max:2000'],
        ]);

        $maintenance->update(['notes' => $data['notes'] ?? null]);

        return back()->with('success', 'Maintenance notes updated.');
    }

    /** The unit comes back into service at the completed date. */
    public function complete(Request $request, FleetMaintenance $maintenance): RedirectResponse
    {
        $this->ensureSameTenant($request, $maintenance);

        abort_unless($maintenance->isOpen(), 422, 'This maintenance job has already been completed.');

        $data = $request->validate([
            'completed_on' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:'.$maintenance->started_on->toDateString()],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'max:2000'],
        ]);

        $product = $maintenance->product;

        DB::transaction(function () use ($product, $maintenance, $data) {
            $maintenance->update([
                'completed_on' => Carbon::parse($data['completed_on'])->toDateString(),
                'cost' => $data['cost'] ?? $maintenance->cost,
                'notes' => $data['notes'] ?? $maintenance->notes,
            ]);

            // Only put it back if it has not been disposed of meanwhile.
            if (! $product->isDisposed()) {
                $product->update([
                    'fleet_status' => Product::FLEET_ACTIVE,
                    'is_active' => true,
                ]);
            }
        });

        AuditLog::record('maintenance.completed', [
            'equipment_number' => $product->equipment_number,
            'reason' => $maintenance->reason,
            'vendor' => $maintenance->vendor,
            'started_on' => $maintenance->started_on->toDateString(),
            'completed_on' => $maintenance->completed_on->toDateString(),
            'days_out' => $maintenance->daysOut(),
            'cost' => $maintenance->cost,
        ], $request->user(), $product->business_id, $product);

        $currency = $product->business?->currency;

        return redirect()
            ->route('maintenance.index')
            ->with('success', $product->equipment_number.' back in service after '
                .$maintenance->daysOut().' '.($maintenance->daysOut() === 1 ? 'day' : 'days')
                .($maintenance->cost !== null ? ' — maintenance cost '.Money::format($maintenance->cost, $currency) : '')
                .'.');
    }

    /** Only a live unit of this tenant can go out for repair. */
    private function ensureAvailableForMaintenance(Request $request, Product $product): void
    {
        if ($request->user()->role !== 'super_admin' && $product->business_id !== $request->user()->business_id) {
            abort(404);
        }

        abort_unless($product->isRental(), 404);

        $problem = match (true) {
            $product->isDisposed() => 'This unit has been disposed of.',
            $product->isInServiceHold() => 'A unit in service hold has not joined the fleet yet.',
            $product->isInMaintenance() => 'This unit is already out for maintenance.',
            ! $product->is_active => 'Only a unit in service can be sent for maintenance.',
            default => null,
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['reason' => $problem]);
        }
    }

    /** A unit currently hired out has to come back before it can be repaired. */
    private function assertNotOnHire(Product $product): void
    {
        $onHire = RentalItem::query()
            ->where('product_id', $product->id)
            ->whereHas('contract', fn ($query) => $query->whereNotIn('status', ['returned', 'cancelled']))
            ->exists();

        if ($onHire) {
            throw ValidationException::withMessages([
                'reason' => 'A unit that is out on hire cannot be sent for maintenance yet.',
            ]);
        }
    }

    private function ensureSameTenant(Request $request, FleetMaintenance $maintenance): void
    {
        if ($request->user()->role !== 'super_admin' && $maintenance->business_id !== $request->user()->business_id) {
            abort(404);
        }
    }

    private function startedMessage(Product $product, FleetMaintenance $job): string
    {
        return $product->equipment_number.' is out for maintenance'
            .($job->vendor ? ' with '.$job->vendor : '')
            .($job->expected_return_on ? ', due back '.$job->expected_return_on->format('M j, Y') : '')
            .'. It is not bookable until it returns.';
    }
}
