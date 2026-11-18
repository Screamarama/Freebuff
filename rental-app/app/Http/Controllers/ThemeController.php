<?php

namespace App\Http\Controllers;

use App\Models\Theme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ThemeController extends Controller
{
    /**
     * Edit this tenant's brand colors (seeded from defaults on first visit).
     */
    public function edit(Request $request): View
    {
        $businessId = $request->user()->business_id;
        if (! $businessId) {
            abort(403, 'No tenant assigned to your account.');
        }

        $theme = Theme::firstOrCreate(
            ['business_id' => $businessId],
            Theme::DEFAULTS
        );

        return view('theme.edit', compact('theme'));
    }

    public function update(Request $request): RedirectResponse
    {
        $businessId = $request->user()->business_id;
        if (! $businessId) {
            abort(403, 'No tenant assigned to your account.');
        }

        $validated = $request->validate([
            'primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'text_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'background_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        Theme::updateOrCreate(['business_id' => $businessId], $validated);

        return redirect()->route('theme.edit')->with('success', 'Brand colors saved.');
    }

    /**
     * Back to the stock look without picking each color by hand.
     */
    public function reset(Request $request): RedirectResponse
    {
        $businessId = $request->user()->business_id;
        if (! $businessId) {
            abort(403, 'No tenant assigned to your account.');
        }

        Theme::updateOrCreate(['business_id' => $businessId], Theme::DEFAULTS);

        return redirect()->route('theme.edit')->with('success', 'Brand colors reset to defaults.');
    }
}
