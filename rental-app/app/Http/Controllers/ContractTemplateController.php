<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\ContractTemplate;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class ContractTemplateController extends Controller
{
    /**
     * Show the effective template with placeholders resolved.
     * Tenants see the global boilerplate pre-filled with their
     * business name until they save their own customized copy.
     */
    public function show(Request $request): View
    {
        $businessId = $request->user()->business_id;
        $business = $businessId ? Business::find($businessId) : null;

        // Super admin with no tenant context edits the global boilerplate.
        if ($request->user()->role === 'super_admin' && ! $businessId) {
            $template = ContractTemplate::boilerplate() ?? new ContractTemplate(ContractTemplate::defaultBoilerplate());
            $rendered = $template->exists ? $template->render() : [
                'title' => $template->title,
                'body' => $template->body,
                'footer' => $template->footer ?? '',
            ];
            $isCustomized = false;
            $isGlobal = true;

            return view('contract-template.show', compact('template', 'rendered', 'business', 'isCustomized', 'isGlobal'));
        }

        $template = ContractTemplate::effectiveFor($businessId);

        if (! $template) {
            abort(404, 'No contract template available.');
        }

        $isCustomized = $businessId
            ? $template->business_id === $businessId
            : false;
        $isGlobal = false;
        $rendered = $template->render(['business_id' => $businessId]);

        return view('contract-template.show', compact('template', 'rendered', 'business', 'isCustomized', 'isGlobal'));
    }

    /**
     * Edit the raw template (placeholders intact).
     * Tenants get their own copy seeded from the boilerplate.
     */
    public function edit(Request $request): View
    {
        if ($request->user()->role === 'super_admin' && ! $request->user()->business_id) {
            $template = ContractTemplate::boilerplate() ?? new ContractTemplate([
                'title' => 'Rental Agreement (Boilerplate)',
                'body' => '',
                'footer' => '',
            ]);
            $isGlobal = true;

            return view('contract-template.edit', compact('template', 'isGlobal'));
        }

        $businessId = $request->user()->business_id;
        if (! $businessId) {
            abort(403, 'No tenant assigned to your account.');
        }

        $template = ContractTemplate::where('business_id', $businessId)->first();

        if (! $template) {
            $boilerplate = ContractTemplate::boilerplate();
            $defaults = ContractTemplate::defaultBoilerplate();
            $template = new ContractTemplate([
                'business_id' => $businessId,
                'title' => $boilerplate?->title ?? $defaults['title'],
                'body' => $boilerplate?->body ?? $defaults['body'],
                'footer' => $boilerplate?->footer ?? $defaults['footer'],
            ]);
        }

        $isGlobal = false;

        return view('contract-template.edit', compact('template', 'isGlobal'));
    }

    /**
     * Save the customized copy against the tenant (or the global
     * boilerplate when edited by a super admin).
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|max:255',
            'body' => 'required',
            'footer' => 'nullable',
        ]);

        if ($request->user()->role === 'super_admin' && ! $request->user()->business_id) {
            $template = ContractTemplate::boilerplate() ?? new ContractTemplate(['business_id' => null]);
            $template->fill($validated);
            $template->business_id = null;
            $template->is_active = true;
            $template->save();

            return redirect()->route('contract-template.show')->with('success', 'Boilerplate updated for all tenants.');
        }

        $businessId = $request->user()->business_id;
        if (! $businessId) {
            abort(403, 'No tenant assigned to your account.');
        }

        ContractTemplate::updateOrCreate(
            ['business_id' => $businessId],
            [...$validated, 'is_active' => true]
        );

        return redirect()->route('contract-template.show')->with('success', 'Your customized contract saved.');
    }
}
