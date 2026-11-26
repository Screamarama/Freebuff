<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\FleetDisposal;
use App\Models\Product;
use App\Models\RentalItem;
use App\Models\Reservation;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Sell or scrap a unit: what it fetched against what it was worth that day.
 * The unit leaves availability and the fleet register, but the record stays in
 * history, so the gain or loss is never lost.
 */
class FleetDisposalController extends Controller
{
    public function create(Request $request, Product $product): View
    {
        $this->ensureDisposable($request, $product);
        $this->assertNotInUse($product);

        return view('products.dispose', [
            'product' => $product,
            'bookValue' => $product->bookValue(),
        ]);
    }

    public function store(Request $request, Product $product): RedirectResponse
    {
        $this->ensureDisposable($request, $product);
        $this->assertNotInUse($product);

        $data = $request->validate([
            'type' => ['required', 'in:sold,scrapped'],
            'disposed_on' => ['required', 'date', 'before_or_equal:today'],
            // A blank sale price would record the unit as given away; scrap is
            // allowed to fetch nothing.
            'price' => ['required_if:type,sold', 'nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'max:1000'],
        ]);

        $disposedOn = Carbon::parse($data['disposed_on']);
        $price = round((float) ($data['price'] ?? 0), 2);

        // Freeze what the unit was worth on the day it left, then take it out
        // of the fleet. Nothing here can be undone by editing the unit later.
        $book = $product->bookValue($disposedOn);

        $disposal = DB::transaction(function () use ($request, $product, $data, $disposedOn, $price, $book) {
            $product->update([
                'fleet_status' => Product::FLEET_DISPOSED,
                'is_active' => false,
            ]);

            return $product->disposal()->create([
                'business_id' => $product->business_id,
                'type' => $data['type'],
                'disposed_on' => $disposedOn->toDateString(),
                'price' => $price,
                'book_value' => $book,
                'notes' => $data['notes'] ?? null,
                'recorded_by_id' => $request->user()->id,
            ]);
        });

        AuditLog::record('fleet.disposed', [
            'equipment_number' => $product->equipment_number,
            'type' => $disposal->type,
            'disposed_on' => $disposal->disposed_on->toDateString(),
            'price' => $disposal->price,
            'book_value' => $disposal->book_value,
            'gain' => $disposal->gain(),
            'notes' => $disposal->notes,
        ], $request->user(), $product->business_id, $product);

        return redirect()
            ->route('products.show', $product)
            ->with('success', $this->message($product, $disposal, $product->business?->currency));
    }

    /** Only a live fleet unit of this tenant can be disposed of. */
    private function ensureDisposable(Request $request, Product $product): void
    {
        if ($request->user()->role !== 'super_admin' && $product->business_id !== $request->user()->business_id) {
            abort(404);
        }

        abort_unless($product->isRental(), 404);

        $problem = match (true) {
            $product->isDisposed() => 'This unit has already been disposed of.',
            $product->isInServiceHold() => 'A unit in service hold is not in the fleet yet. Commit or return it instead.',
            default => null,
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['type' => $problem]);
        }
    }

    /** A unit that is out on hire or promised to a booking cannot be sold. */
    private function assertNotInUse(Product $product): void
    {
        $onHire = RentalItem::query()
            ->where('product_id', $product->id)
            ->whereHas('contract', fn ($query) => $query->whereNotIn('status', ['returned', 'cancelled']))
            ->exists();

        $reserved = Reservation::query()
            ->blocking()
            ->where('product_id', $product->id)
            ->exists();

        if ($onHire || $reserved) {
            throw ValidationException::withMessages([
                'type' => 'A unit that is out on hire or held by a reservation cannot be disposed of.',
            ]);
        }
    }

    private function message(Product $product, FleetDisposal $disposal, ?string $currency): string
    {
        $message = $product->equipment_number.' '.($disposal->type === FleetDisposal::SOLD ? 'sold' : 'scrapped')
            .($disposal->price > 0 ? ' for '.Money::format($disposal->price, $currency) : '');

        $gain = $disposal->gain();

        if ($gain === null) {
            return $message.'. Its book value was not available, so no gain or loss was recorded.';
        }

        return $message.' against a book value of '.Money::format($disposal->book_value, $currency)
            .($gain >= 0 ? ' — gain of ' : ' — loss of ').Money::format(abs($gain), $currency).'.';
    }
}
