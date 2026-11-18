<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Product;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Services\CustomerAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request, CustomerAccount $accounts): View
    {
        $user = $request->user();

        // Super admins get the platform summary; everything else is tenant work.
        if ($user->role === 'super_admin' && ! session('impersonator_id')) {
            return view('dashboard-super-admin', [
                'tenants_total' => Business::count(),
                'tenants_active' => Business::where('is_active', true)->count(),
                'products_total' => Product::where('is_active', true)->count(),
                'customers_total' => \App\Models\Customer::count(),
                'reservations_by_status' => Reservation::selectRaw('status, COUNT(*) as total')
                    ->groupBy('status')->pluck('total', 'status'),
                'agreements_by_status' => RentalContract::selectRaw('status, COUNT(*) as total')
                    ->groupBy('status')->pluck('total', 'status'),
                'recent_tenants' => Business::withCount(['products', 'customers', 'reservations', 'rentalContracts'])
                    ->latest()->limit(5)->get(),
            ]);
        }

        $business = $user->business;

        abort_unless($business, 403, 'No tenant assigned to your account.');

        $today = Carbon::today();

        $contracts = fn () => RentalContract::query()
            ->where('business_id', $business->id)
            ->where('status', 'active')
            ->with('customer');

        $pickups = $contracts()
            ->whereDate('start_date', $today)
            ->orderBy('contract_number')
            ->get();

        $returns = $contracts()
            ->whereDate('end_date', $today)
            ->whereNull('actual_return_date')
            ->orderBy('contract_number')
            ->get();

        $overdue = $contracts()
            ->whereNull('actual_return_date')
            ->whereDate('end_date', '<', $today)
            ->orderBy('end_date')
            ->get();

        $reservations = Reservation::query()
            ->where('business_id', $business->id)
            ->blocking()
            ->where('ends_at', '>=', now())
            ->with(['customer', 'product'])
            ->orderBy('starts_at')
            ->get();

        // Sale stock that is gone. Rental units on rent are already covered by
        // the pickups and returns cards, so they are not "low stock" here.
        $hasSaleItems = Product::query()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->where('product_type', 'sale')
            ->exists();

        $lowStock = Product::query()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->where('product_type', 'sale')
            ->where('stock_quantity', '>', 0)
            ->where('available_quantity', '<=', 0)
            ->orderBy('name')
            ->get();

        $aging = $accounts->aging($business->id);
        $unpaidCustomers = collect($aging['rows'])->filter(fn (array $row) => $row['open'] > 0);

        return view('dashboard', [
            'business' => $business,
            'today' => $today,
            'pickups' => $pickups,
            'returns' => $returns,
            'overdue' => $overdue,
            'reservations' => $reservations,
            'lowStock' => $lowStock,
            'hasSaleItems' => $hasSaleItems,
            'aging' => $aging,
            'unpaidCustomers' => $unpaidCustomers,
        ]);
    }
}
