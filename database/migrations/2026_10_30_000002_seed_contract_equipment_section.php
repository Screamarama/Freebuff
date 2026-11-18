<?php

use App\Models\ContractTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Stock boilerplate bodies this migration converges to the version
     * with the 2. EQUIPMENT section: the original pre-header wording and
     * the header-only (v1) wording. Anything customized is never touched.
     */
    private const STOCK_VERSIONS = [
        <<<'TEXT'
        RENTAL AGREEMENT

        This Rental Agreement ("Agreement") is entered into by and between {{business_name}} ("Owner") and {{customer_name}} ("Renter").

        1. BUSINESS DETAILS
        Owner: {{business_name}}
        Address: {{business_address}}
        Phone: {{business_phone}} | Email: {{business_email}}

        2. RENTAL DETAILS
        Contract No: {{contract_number}}
        Rental period: {{start_date}} to {{end_date}}
        Total amount: {{total_amount}}

        3. TERMS
        3.1 The Renter agrees to return all items in the same condition, ordinary wear excepted.
        3.2 Late returns incur a late fee as stated in the footer below.
        3.3 The deposit paid will be applied against damage, loss, or late fees.
        3.4 The Renter is responsible for loss or damage from pickup until return.

        4. SIGNATURES
        Owner ({{business_name}}): ______________________  Date: __________
        Renter ({{customer_name}}): ______________________  Date: __________
        TEXT,
        <<<'TEXT'
        1. THE PARTIES. This Rental Agreement (“Agreement”) is made and entered into on {{contract_date}} by and between:

        Owner: {{business_name}} located at {{business_address}} (“Owner”), and

        Renter: {{customer_name}} located at {{customer_address}} (“Renter”).

        2. BUSINESS DETAILS
        Owner: {{business_name}}
        Address: {{business_address}}
        Phone: {{business_phone}} | Email: {{business_email}}

        3. RENTAL DETAILS
        Contract No: {{contract_number}}
        Rental period: {{start_date}} to {{end_date}}
        Total amount: {{total_amount}}

        4. TERMS
        4.1 The Renter agrees to return all items in the same condition, ordinary wear excepted.
        4.2 Late returns incur a late fee as stated in the footer below.
        4.3 The deposit paid will be applied against damage, loss, or late fees.
        4.4 The Renter is responsible for loss or damage from pickup until return.

        5. SIGNATURES
        Owner ({{business_name}}): ______________________  Date: __________
        Renter ({{customer_name}}): ______________________  Date: __________
        TEXT,
    ];

    public function up(): void
    {
        $defaults = ContractTemplate::defaultBoilerplate();

        $template = ContractTemplate::whereNull('business_id')->first();

        if (! $template) {
            ContractTemplate::create([...$defaults, 'business_id' => null, 'is_active' => true]);

            return;
        }

        $normalize = fn (string $text): string => (string) preg_replace('/\s+/', ' ', trim($text));
        $body = (string) $template->body;

        $isStock = trim($body) === '';
        foreach (self::STOCK_VERSIONS as $stock) {
            if ($normalize($body) === $normalize($stock)) {
                $isStock = true;
                break;
            }
        }

        if ($isStock) {
            $template->update([
                'title' => $defaults['title'],
                'body' => $defaults['body'],
                'footer' => $template->footer ?: $defaults['footer'],
            ]);
        }
    }

    public function down(): void
    {
        // Content migration: leave data in place.
    }
};
