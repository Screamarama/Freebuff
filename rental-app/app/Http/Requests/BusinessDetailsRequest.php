<?php

namespace App\Http\Requests;

use App\Models\Business;
use App\Support\Countries;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The business fields a tenant admin edits on their own profile and a super
 * admin edits on the tenant record. Same rules either way, so a value saved
 * from one screen is never rejected or mangled by the other.
 */
class BusinessDetailsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'country' => ['sometimes', 'required', 'string', Rule::in(Countries::codes())],
            // The state must belong to the country, so a US tenant cannot end up
            // with an Ontario address by way of a stale dropdown.
            'state' => $this->stateRules(),
            'city' => ['nullable', 'string', 'max:100'],
            // Absent keeps the current currency; the forms always send it.
            'currency' => ['sometimes', 'required', 'string', Rule::in(Money::codes())],
        ];

        // Percentages, optionally fractional: 8.875 means 8.875%.
        foreach (Business::SALES_TAX_FIELDS as $field) {
            $rules[$field] = ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,3'];
        }

        // US tenants tax with state/county/city sales tax, everyone else with
        // VAT. Saving both would tax the same charge twice.
        $exclusive = $this->country() === 'US'
            ? ['vat']
            : ['state_sales_tax', 'county_sales_tax', 'city_sales_tax'];

        foreach ($exclusive as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * The country being saved. The business profile has no country field, so it
     * edits the country the tenant already has rather than assuming US.
     */
    private function country(): string
    {
        return (string) ($this->input('country')
            ?: $this->route('tenant')?->country
            ?: $this->user()?->business?->country
            ?: 'US');
    }

    /** @return array<int, string> */
    private function stateRules(): array
    {
        $subdivisions = Countries::subdivisions($this->country());

        if ($subdivisions === []) {
            return ['nullable', 'string', 'max:100'];
        }

        // A state stored before this list existed stays valid while the country
        // is unchanged, so saving other details cannot silently blank it.
        // Switching country drops that allowance and forces a new state.
        $business = $this->route('tenant') ?? $this->user()?->business;

        if ($business !== null && strtoupper((string) $business->country) === $this->country()) {
            $subdivisions = array_merge($subdivisions, array_filter([$business->state]));
        }

        return ['nullable', 'string', Rule::in($subdivisions)];
    }

    /** Stored uppercase whichever form sent it. */
    protected function prepareForValidation(): void
    {
        $normalise = [];

        foreach (['currency', 'country'] as $field) {
            if ($this->has($field)) {
                $normalise[$field] = strtoupper((string) $this->input($field));
            }
        }

        $this->merge($normalise);
    }
}
