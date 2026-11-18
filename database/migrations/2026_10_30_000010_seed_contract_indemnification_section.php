<?php

use App\Models\ContractTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Stock boilerplate without the 8. INDEMNIFICATION section.
     * Rows still carrying it verbatim converge to the new version;
     * anything customized is never touched.
     */
    private const PREVIOUS_DEFAULT_BODY = <<<'TEXT'
        1. THE PARTIES. This Rental Agreement (“Agreement”) is made and entered into on {{contract_date}} by and between:

        Owner: {{business_name}} located at {{business_address}} (“Owner”), and

        Renter: {{customer_name}} located at {{customer_address}} (“Renter”).

        2. EQUIPMENT. The Owner and Renter agree to enter into a binding agreement for the leasing of the following:
        {{rented_items}}

        3. TERM. The rental period begins on {{start_date}}, and continues until:
        ☐ - End Date. This Agreement ends on {{end_date}}. Hereinafter known as the “Term.”

        4. USE OF EQUIPMENT. The Renter agrees to use the Equipment for its intended use and legal purposes. Any use of the Equipment outside of its intended use or for unlawful purposes can result in the termination of this Agreement.

        5. RENTER'S RESPONSIBILITY. It is the Renter's responsibility to maintain the Equipment in accordance with industry standards during the Term. Any repairs, modifications, or damage caused to the Equipment, except for common wear and tear resulting from its intended use, shall be the Renter's responsibility.

        6. REPAIRS. If, at any time, the Equipment ceases to function in its intended use at no fault of the Renter, the Owner agrees to either:
        a.) Replace the Equipment. The Owner shall replace the Equipment with another that is equal in respect to brand, function, features, and purpose. During the exchange period under which the Renter has notified the Owner of the requested repair and the time it takes to replace the Equipment, there shall be no lease payment owed by the Renter; or
        b.) Repair the Equipment. The Owner shall repair the Equipment on a timely basis and in accordance with industry standards.
        If the Renter is not at fault for the repair needed to the Equipment, the Renter shall not be charged during the exchange period under which the Renter has notified the Owner of the Equipment's malfunction to when the Equipment is repaired or replaced and returned to the Renter. Any payments made shall be credited for future use of the Equipment.

        7. DEFAULT. In the event of Default by the Renter, the Owner may terminate this Agreement and take possession of the Equipment without prejudice to any other remedies the Owner may have.

        8. BUSINESS DETAILS
        Owner: {{business_name}}
        Address: {{business_address}}
        Phone: {{business_phone}} | Email: {{business_email}}

        9. RENTAL DETAILS
        Contract No: {{contract_number}}
        Rental period: {{start_date}} to {{end_date}}
        Total amount: {{total_amount}}

        10. TERMS
        10.1 The Renter agrees to return all items in the same condition, ordinary wear excepted.
        10.2 Late returns incur a late fee as stated in the footer below.
        10.3 The deposit paid will be applied against damage, loss, or late fees.
        10.4 The Renter is responsible for loss or damage from pickup until return.

        11. SIGNATURES
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
