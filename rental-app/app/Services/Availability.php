<?php

namespace App\Services;

use App\Models\Product;
use App\Models\RentalContract;
use App\Models\RentalItem;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * How many units of a product are free over a period. Reservations and rental
 * agreements both hold stock, so every path that promises units checks here
 * rather than looking at one of the two in isolation.
 *
 * A product with no stock recorded is treated as untracked, as before, so the
 * check never blocks an item whose counts were never set up.
 */
class Availability
{
    /**
     * Units already promised for the product across the period by blocking
     * reservations and the line items of live agreements. The record being
     * edited is passed in so it is not counted against itself.
     */
    public function committed(
        Product $product,
        Carbon|string $from,
        Carbon|string $to,
        ?int $ignoreReservationId = null,
        ?int $ignoreContractId = null,
    ): int {
        $from = Carbon::parse($from);
        $to = Carbon::parse($to);

        $reserved = $this->reservationsHolding($from, $to)
            ->where('product_id', $product->id)
            ->when($ignoreReservationId, fn ($query, $id) => $query->where('id', '!=', $id))
            ->sum('quantity');

        $onAgreements = $this->agreementItemsHolding($from, $to)
            ->where('product_id', $product->id)
            ->when($ignoreContractId, fn ($query, $id) => $query->whereRelation('contract', 'id', '!=', $id))
            ->sum('quantity');

        return (int) $reserved + (int) $onAgreements;
    }

    /** Blocking reservations overlapping the period. */
    private function reservationsHolding(Carbon $from, Carbon $to): Builder
    {
        return Reservation::query()
            ->blocking()
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from);
    }

    /** Line items of live agreements whose dates cover the period. */
    private function agreementItemsHolding(Carbon $from, Carbon $to): Builder
    {
        return RentalItem::query()
            ->whereHas('contract', fn ($query) => $query
                ->whereNotIn('status', ['returned', 'cancelled'])
                ->whereDate('start_date', '<=', $to->toDateString())
                ->whereDate('end_date', '>=', $from->toDateString()));
    }

    /**
     * Units still free for the period, or PHP_INT_MAX when the product's stock
     * is not tracked.
     */
    public function availableFor(
        Product $product,
        Carbon|string $from,
        Carbon|string $to,
        ?int $ignoreReservationId = null,
        ?int $ignoreContractId = null,
    ): int {
        if ((int) $product->stock_quantity <= 0) {
            return PHP_INT_MAX;
        }

        return max(0, (int) $product->stock_quantity - $this->committed(
            $product, $from, $to, $ignoreReservationId, $ignoreContractId
        ));
    }

    /**
     * Today's holdings as two aggregate subqueries, one row per product, so a
     * list can read availability from the same query that loads the products.
     *
     * @return array{0: Builder, 1: Builder}
     */
    public function heldTodaySubqueries(): array
    {
        [$start, $end] = $this->todayBounds();

        return [
            $this->reservationsHolding($start, $end)
                ->selectRaw('COALESCE(SUM(quantity), 0)')
                ->whereColumn('reservations.product_id', 'products.id'),
            $this->agreementItemsHolding($start, $end)
                ->selectRaw('COALESCE(SUM(quantity), 0)')
                ->whereColumn('rental_items.product_id', 'products.id'),
        ];
    }

    /**
     * Units free today — what the product list shows for a rental, derived
     * from the same holdings the availability check uses.
     */
    public function availableToday(Product $product): int
    {
        [$from, $to] = $this->todayBounds();

        return max(0, (int) $product->stock_quantity - $this->committed($product, $from, $to));
    }

    /**
     * Day-by-day holdings over a range, from the same rules as committed(),
     * for the product page's availability list.
     *
     * @return list<array{date: Carbon, booked: int, available: int}>
     */
    public function dailyAvailability(Product $product, Carbon|string $from, Carbon|string $to): array
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        $reservations = $this->reservationsHolding($from, $to->copy()->endOfDay())
            ->where('product_id', $product->id)
            ->get(['quantity', 'starts_at', 'ends_at']);

        $items = $this->agreementItemsHolding($from, $to->copy()->endOfDay())
            ->where('product_id', $product->id)
            ->with('contract:id,start_date,end_date')
            ->get(['id', 'contract_id', 'quantity']);

        $days = [];

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $start = $day->copy()->startOfDay();
            $end = $day->copy()->endOfDay();

            $booked = $reservations
                ->filter(fn ($reservation) => $reservation->starts_at->lt($end) && $reservation->ends_at->gt($start))
                ->sum('quantity')
                + $items
                    ->filter(fn ($item) => $item->contract->start_date->lte($day) && $item->contract->end_date->gte($day))
                    ->sum('quantity');

            $days[] = [
                'date' => $day->copy(),
                'booked' => (int) $booked,
                'available' => max(0, (int) $product->stock_quantity - (int) $booked),
            ];
        }

        return $days;
    }

    /** Start and end of today, so every "now" figure uses the same window. */
    private function todayBounds(): array
    {
        return [now()->startOfDay(), now()->endOfDay()];
    }

    /**
     * Whether one more line of the product fits on the agreement, counting the
     * agreement's other lines of it but not the line being changed.
     */
    public function canAddLine(
        RentalContract $contract,
        Product $product,
        int $quantity,
        ?int $ignoreItemId = null,
    ): bool {
        $onContract = (int) $contract->items()
            ->where('product_id', $product->id)
            ->when($ignoreItemId, fn ($query, $id) => $query->where('id', '!=', $id))
            ->sum('quantity');

        return $this->availableFor(
            $product,
            $contract->start_date,
            $contract->end_date,
            ignoreContractId: $contract->id
        ) >= $quantity + $onContract;
    }

    /**
     * Guard an existing agreement before its dates are changed: its own lines
     * must still fit around everything else holding those units.
     */
    public function assertContractCanHold(RentalContract $contract): void
    {
        $lines = $contract->items()->with('product')->get()->groupBy('product_id');

        foreach ($lines as $productLines) {
            $product = $productLines->first()->product;

            if (! $product || $product->isSale()) {
                continue;
            }

            $quantity = (int) $productLines->sum('quantity');

            if ($this->availableFor(
                $product,
                $contract->start_date,
                $contract->end_date,
                ignoreContractId: $contract->id
            ) < $quantity) {
                abort(422, 'The new dates overlap another booking for ' . $product->name . '.');
            }
        }
    }

    /**
     * Reservation guard, keeping the 422 answers the reservation forms expect.
     */
    public function assertReservation(
        Product $product,
        int $quantity,
        Carbon|string $from,
        Carbon|string $to,
        ?int $ignoreReservationId = null,
    ): void {
        if ($quantity > (int) $product->stock_quantity) {
            abort(422, 'Not enough units in stock for that quantity.');
        }

        if ($this->availableFor($product, $from, $to, $ignoreReservationId) < $quantity) {
            abort(422, 'Not enough units available for that period (overlaps another booking).');
        }
    }
}
