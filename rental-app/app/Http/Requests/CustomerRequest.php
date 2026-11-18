<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Customer create and edit save the same fields, so they validate the same
 * way; only the unique customer code has to ignore the record being edited.
 */
class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = $this->route('customer');
        $user = $this->user();

        if ($customer === null) {
            // Creating: a tenant user needs a tenant.
            abort_if($user->role !== 'super_admin' && ! $user->business_id, 403, 'No tenant assigned to your account.');

            return true;
        }

        abort_if($user->role !== 'super_admin' && $customer->business_id !== $user->business_id, 404);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $customer = $this->route('customer');
        $businessId = $customer?->business_id ?? $this->user()->business_id;

        return [
            'customer_code' => [
                'required',
                'max:50',
                Rule::unique('customers', 'customer_code')->ignore($customer?->id)->where(fn ($q) => $q->where('business_id', $businessId)),
            ],
            'first_name' => 'required|max:100',
            'last_name' => 'required|max:100',
            'email' => 'nullable|email|max:255',
            'phone' => 'required|max:50',
            'address' => 'nullable',
            'city' => 'nullable|max:100',
            'state' => 'nullable|max:100',
            'postal_code' => 'nullable|max:20',
            'country' => 'nullable|max:100',
            'id_number' => 'nullable|max:50',
            'date_of_birth' => 'nullable|date',
            'notes' => 'nullable',
            'account_type' => ['required', 'in:cash,charge'],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'required_if:account_type,charge'],
            'tax_exemption_number' => ['nullable', 'string', 'max:100'],
            'tax_exempt_copy_on_file' => ['boolean'],
            'tax_exemption_expires_on' => ['nullable', 'date'],
            'tax_exemption_reason' => ['nullable', 'string', 'max:150'],
        ];
    }
}
