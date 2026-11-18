<?php

use App\Models\ContractTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The previous stock wording (pre THE PARTIES header). Rows still
     * carrying it verbatim are converged to the new canonical header;
     * anything customized is never touched.
     */
    private const PREVIOUS_DEFAULT_BODY = <<<'TEXT'
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
        TEXT;

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
        if (trim($body) === '' || $normalize($body) === $normalize(self::PREVIOUS_DEFAULT_BODY)) {
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
