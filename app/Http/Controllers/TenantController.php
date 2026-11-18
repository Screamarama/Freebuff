<?php

namespace App\Http\Controllers;

use App\Http\Requests\BusinessDetailsRequest;
use App\Http\Requests\StoreTenantRequest;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\ImpersonationLog;
use App\Models\User;
use App\Support\Csv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Http\RedirectResponse;

use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenantController extends Controller
{
    public function index(): View
    {
        // Summary counts only — super admins manage the details by
        // impersonating the tenant (see impersonate()), never from cross-tenant lists.
        $tenants = Business::withCount(['users', 'products', 'customers', 'reservations', 'rentalContracts'])
            ->with(['users' => fn ($query) => $query->orderBy('name')])
            ->latest()
            ->paginate(12);

        return view('tenants.index', compact('tenants'));
    }

    public function create(): View
    {
        return view('tenants.create');
    }

    /** A tenant's business details stay editable after the tenant is created. */
    public function edit(Business $tenant): View
    {
        return view('tenants.edit', compact('tenant'));
    }

    public function update(BusinessDetailsRequest $request, Business $tenant): RedirectResponse
    {
        $tenant->applyDetails($request->validated(), $request->user());

        return redirect()
            ->route('tenants.index')
            ->with('success', '"'.$tenant->name.'" updated.');
    }

    public function store(StoreTenantRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $tenant = DB::transaction(function () use ($validated) {
            $tenant = Business::create([
                'is_active' => true,
                ...Business::taxesForCountry(Arr::only($validated, [
                    'name', 'email', 'phone', 'address', 'state', 'country', 'city',
                    'currency', ...Business::SALES_TAX_FIELDS,
                ]), $validated['country']),
            ]);

            // role / business_id / email_verified_at are NOT in the User model's
            // $fillable, so assign them explicitly instead of silently losing them.
            $admin = new User([
                'name' => $validated['admin_name'],
                'email' => $validated['admin_email'],
                'password' => $validated['password'],
            ]);
            $admin->role = 'admin';
            $admin->business_id = $tenant->id;
            $admin->email_verified_at = now();
            $admin->save();

            return $tenant;
        });

        return redirect()
            ->route('tenants.index')
            ->with('success', 'Tenant "'. $tenant->name .'" created. Admin account: '. $validated['admin_email']);
    }

    /**
     * Staff logins are created by a super admin — there is no public
     * registration — so a tenant's people are added from its own card.
     */
    public function createUser(Business $tenant): View|RedirectResponse
    {
        if ($blocked = $this->suspendedTenantRedirect($tenant)) {
            return $blocked;
        }

        return view('tenants.users.create', compact('tenant'));
    }

    public function storeUser(Request $request, Business $tenant): RedirectResponse
    {
        if ($blocked = $this->suspendedTenantRedirect($tenant)) {
            return $blocked;
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'string', 'in:admin,manager,clerk'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        // role / business_id / email_verified_at are NOT in the User model's
        // $fillable, so assign them explicitly instead of silently losing them.
        $user = new User([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);
        $user->role = $validated['role'];
        $user->business_id = $tenant->id;
        $user->email_verified_at = now();
        $user->save();

        return redirect()
            ->route('tenants.index')
            ->with('success', 'Login created for '.$validated['email'].' ('.$validated['role'].') at "'.$tenant->name.'".');
    }

    /**
     * Set a new password for one of the tenant's logins. There is no email
     * reset to fall back on, so the super admin hands the password over.
     */
    public function resetUserPassword(Request $request, Business $tenant, User $user): RedirectResponse
    {
        $this->ensureBelongsToTenant($tenant, $user);

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        // The model's "hashed" cast encrypts the value on assignment.
        $user->password = $validated['password'];
        $user->remember_token = null;
        $user->save();

        $this->endSessions([$user->id]);

        AuditLog::record(
            'tenant.user.password_reset',
            $this->accountDetails($tenant, $user),
            businessId: $tenant->id,
            subject: $user,
        );

        return back()->with('success', 'Password updated for '.$user->email.'.');
    }

    /**
     * Enable or disable a tenant login. Disabled accounts cannot sign in.
     */
    public function toggleUser(Business $tenant, User $user): RedirectResponse
    {
        $this->ensureBelongsToTenant($tenant, $user);

        $user->is_active = ! $user->is_active;
        $user->save();

        if (! $user->is_active) {
            $this->endSessions([$user->id]);
        }

        AuditLog::record(
            $user->is_active ? 'tenant.user.activated' : 'tenant.user.deactivated',
            $this->accountDetails($tenant, $user),
            businessId: $tenant->id,
            subject: $user,
        );

        return back()->with(
            'success',
            $user->email.' '.($user->is_active ? 'reactivated.' : 'deactivated.')
        );
    }

    /**
     * Nobody can sign in to a suspended tenant, so new logins for one are
     * refused server-side rather than just hidden in the view.
     */
    private function suspendedTenantRedirect(Business $tenant): ?RedirectResponse
    {
        if ($tenant->is_active) {
            return null;
        }

        return redirect()
            ->route('tenants.index')
            ->with('error', $tenant->name.' is suspended. Activate it before adding logins.');
    }

    /**
     * Context shared by the staff-account entries: which tenant, and which
     * login was touched — both snapshotted so the history outlives them.
     */
    private function accountDetails(Business $tenant, User $user): array
    {
        return [
            'name' => $tenant->name,
            'subject_name' => $user->name,
            'subject_email' => $user->email,
        ];
    }

    /**
     * A tenant's users can only be managed through that tenant's own route.
     */
    private function ensureBelongsToTenant(Business $tenant, User $user): void
    {
        abort_unless((int) $user->business_id === (int) $tenant->id, 404);
    }

    /**
     * Sign the user out everywhere, so a changed password or a disabled
     * account takes effect immediately instead of at session expiry.
     */
    private function endSessions(array $userIds): void
    {
        if (config('session.driver') !== 'database' || $userIds === []) {
            return;
        }

        DB::table(config('session.table', 'sessions'))->whereIn('user_id', $userIds)->delete();
    }

    /**
     * Show the audit log: who impersonated whom, plus sign-ins and account
     * changes. The tenant and date filters narrow both tables; "action" only
     * affects the activity table, since impersonation has no action type.
     */
    public function auditLogs(Request $request): View
    {
        [$tenantId, $action, $from, $to] = $this->auditFilters($request);

        // Keep the filters on the pager links.
        $logs = $this->impersonationLogs($tenantId, $from, $to)
            ->paginate(20)
            ->withQueryString();

        // The two histories share the page but keep their own pagination, so
        // paging one never shifts the other's rows.
        $accessLogs = $this->accountActivity($tenantId, $action, $from, $to)
            ->paginate(20, ['*'], 'access_page')
            ->withQueryString();

        $tenants = Business::orderBy('name')->get(['id', 'name']);
        $actions = AuditLog::ACTIONS;
        $filtered = $tenantId !== null || $action !== null || $from !== null || $to !== null;

        return view('tenants.audit-logs', compact(
            'logs', 'accessLogs', 'tenants', 'actions', 'filtered', 'tenantId', 'action', 'from', 'to'
        ));
    }

    /**
     * Download everything the filters match — the whole history, not just the
     * page on screen. Both tables share one file, impersonation first as on the
     * page, and a Section column says which table a row came from.
     */
    public function exportAuditLogs(Request $request): StreamedResponse
    {
        [$tenantId, $action, $from, $to] = $this->auditFilters($request);

        $impersonations = $this->impersonationLogs($tenantId, $from, $to);
        $activity = $this->accountActivity($tenantId, $action, $from, $to);

        $filename = 'audit-log-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($impersonations, $activity) {
            $file = fopen('php://output', 'w');

            // The BOM is what makes Excel read the file as UTF-8.
            fwrite($file, "\xEF\xBB\xBF");

            fputcsv($file, [
                'Section', 'When', 'Actor', 'Actor email', 'Tenant',
                'Event', 'Action', 'Target', 'Status', 'From IP', 'Details',
            ]);

            // lazyByIdDesc walks the history in chunks, newest first, so a wide
            // date range streams out instead of being held in memory.
            foreach ($impersonations->reorder()->lazyByIdDesc() as $log) {
                fputcsv($file, Csv::row([
                    'Impersonation',
                    $log->started_at?->format('Y-m-d H:i:s'),
                    $log->impersonator?->name,
                    $log->impersonator?->email,
                    $log->business?->name,
                    'Impersonation',
                    null,
                    $log->impersonated
                        ? $log->impersonated->name.' · '.$log->impersonated->email
                        : null,
                    $log->isActive() ? 'Active' : 'Ended',
                    null,
                    null,
                ]));
            }

            foreach ($activity->reorder()->lazyByIdDesc() as $log) {
                fputcsv($file, Csv::row([
                    'Activity',
                    $log->created_at?->format('Y-m-d H:i:s'),
                    $log->actorName(),
                    $log->actorEmail(),
                    $log->business?->name ?? ($log->details['name'] ?? null),
                    $log->actionLabel(),
                    $log->action,
                    $log->subjectLabel(),
                    null,
                    $log->ip_address,
                    $log->details
                        ? json_encode($log->details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        : null,
                ]));
            }

            fclose($file);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The four filters the page and the export share, already parsed: the dates
     * become whole days so "from" and "to" include the days at both ends.
     *
     * @return array{0: ?int, 1: ?string, 2: ?Carbon, 3: ?Carbon}
     */
    private function auditFilters(Request $request): array
    {
        $validated = $request->validate([
            'tenant' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return [
            isset($validated['tenant']) ? (int) $validated['tenant'] : null,
            $validated['action'] ?? null,
            isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : null,
            isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : null,
        ];
    }

    /**
     * Impersonation rows matching the tenant and date filters.
     */
    private function impersonationLogs(?int $tenantId, ?Carbon $from, ?Carbon $to): Builder
    {
        return ImpersonationLog::with(['impersonator', 'impersonated', 'business'])
            ->when($tenantId, fn ($query) => $query->where('business_id', $tenantId))
            ->when($from, fn ($query) => $query->where('started_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('started_at', '<=', $to))
            ->latest('started_at');
    }

    /**
     * Sign-ins and account changes matching the filters. The action filter only
     * applies here: impersonation has no action type.
     */
    private function accountActivity(?int $tenantId, ?string $action, ?Carbon $from, ?Carbon $to): Builder
    {
        return AuditLog::with(['user', 'business'])
            ->when($tenantId, fn ($query) => $query->where('business_id', $tenantId))
            ->when($action, fn ($query) => $query->where('action', $action))
            ->when($from, fn ($query) => $query->where('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('created_at', '<=', $to))
            ->latest('created_at')
            ->latest('id');
    }

    public function toggle(Request $request, Business $tenant): RedirectResponse
    {
        $tenant->update(['is_active' => ! $tenant->is_active]);

        AuditLog::record(
            $tenant->is_active ? 'tenant.activated' : 'tenant.suspended',
            ['name' => $tenant->name],
            $request->user(),
            $tenant->id,
            $tenant,
        );

        // Suspending a tenant signs its people out; they are also refused at
        // login while it stays inactive (see LoginRequest).
        if (! $tenant->is_active) {
            $this->endSessions($tenant->users()->pluck('id')->all());
        }

        return back()->with('success', $tenant->name . ($tenant->is_active ? ' activated.' : ' deactivated.'));
    }

    /**
     * Log the super admin into this tenant's admin account (impersonation).
     */
    public function impersonate(Request $request, Business $tenant): RedirectResponse
    {
        $admin = $tenant->users()->where('role', 'admin')->firstOrFail();

        $impersonatorId = $request->user()->getAuthIdentifier();
        $request->session()->put('impersonator_id', $impersonatorId);

        ImpersonationLog::create([
            'impersonator_id' => $impersonatorId,
            'impersonated_id' => $admin->id,
            'business_id' => $tenant->id,
            'started_at' => now(),
        ]);

        Auth::login($admin);

        return redirect()->route('dashboard')
            ->with('success', 'Now impersonating "'.$tenant->name.'" as '.$admin->email.'.');
    }

    /**
     * Return to the super admin's own session.
     */
    public function stopImpersonating(Request $request): RedirectResponse
    {
        $impersonatorId = $request->session()->pull('impersonator_id');

        if (! $impersonatorId) {
            return redirect()->route('dashboard');
        }

        $impersonator = User::findOrFail($impersonatorId);
        Auth::login($impersonator);

        ImpersonationLog::where('impersonator_id', $impersonatorId)
            ->whereNull('stopped_at')
            ->latest('started_at')
            ->first()
            ?->update(['stopped_at' => now()]);

        return redirect()->route('tenants.index')
            ->with('success', 'Back to your own session.');
    }
}