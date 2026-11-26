<?php

namespace App\Http\Controllers;

use App\Models\FleetDisposal;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Units that have left the fleet, what they fetched and their gain or loss. */
class FleetHistoryController extends Controller
{
    public function index(Request $request): View
    {
        $business = $request->user()->business;

        $disposals = FleetDisposal::query()
            ->where('business_id', $business->id)
            ->with(['product.equipmentModel', 'recordedBy'])
            ->orderByDesc('disposed_on')
            ->orderByDesc('id')
            ->get();

        // A unit without a book value still counts as disposed and its proceeds
        // are real; only the gain or loss is unknown for it.
        $valued = $disposals->filter(fn (FleetDisposal $disposal) => $disposal->book_value !== null);

        return view('reports.fleet-history', [
            'disposals' => $disposals,
            'count' => $disposals->count(),
            'proceeds' => round((float) $disposals->sum('price'), 2),
            'book' => round((float) $valued->sum('book_value'), 2),
            'gain' => round((float) $valued->sum(fn (FleetDisposal $disposal) => $disposal->gain() ?? 0), 2),
            'unvalued' => $disposals->count() - $valued->count(),
            'currency' => $business->currency,
        ]);
    }
}
