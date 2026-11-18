<?php

namespace App\Http\Controllers;

use App\Http\Requests\BusinessDetailsRequest;
use App\Models\Business;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BusinessProfileController extends Controller
{
    /**
     * Edit the tenant's own business details. These values feed the
     * contract placeholders ({{business_name}}, {{business_address}},
     * {{business_phone}}, {{business_email}}, {{company_state}}).
     */
    public function edit(Request $request): View
    {
        $business = $this->tenant($request);

        return view('business.edit', compact('business'));
    }

    public function update(BusinessDetailsRequest $request): RedirectResponse
    {
        $business = $this->tenant($request);

        // The same entry a super admin's edit writes, so one log tells the
        // whole story of who changed the tenant's details and when.
        $business->applyDetails($request->validated(), $request->user());

        return redirect()->route('business.edit')->with('success', 'Business details saved.');
    }

    private function tenant(Request $request): Business
    {
        $businessId = $request->user()->business_id;

        if (! $businessId) {
            abort(403, 'No tenant assigned to your account.');
        }

        return Business::findOrFail($businessId);
    }
}
