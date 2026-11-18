<?php

namespace App\Http\Requests;

use App\Support\Countries;
use App\Support\Money;
use Illuminate\Validation\Rule;

/**
 * Tenant creation adds the first admin login to the business fields the edit
 * forms save, so both share the country/state/currency rules below.
 */
class StoreTenantRequest extends BusinessDetailsRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'currency' => ['required', 'string', Rule::in(Money::codes())],
            'country' => ['required', 'string', Rule::in(Countries::codes())],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);
    }
}
