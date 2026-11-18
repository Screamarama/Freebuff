<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractTemplate extends Model
{
    protected $fillable = [
        'business_id',
        'title',
        'body',
        'footer',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Placeholders supported in title/body/footer:
     * {{business_name}}, {{business_address}}, {{business_phone}},
     * {{business_email}}, {{company_state}}, {{customer_name}}, {{customer_address}},
     * {{rented_items}}, {{contract_number}}, {{contract_date}}, {{start_date}},
     * {{end_date}}, {{total_amount}}
     */
    public static function placeholders(): array
    {
        return [
            'business_name',
            'business_address',
            'business_phone',
            'business_email',
            'company_state',
            'customer_name',
            'customer_address',
            'rented_items',
            'contract_number',
            'contract_date',
            'start_date',
            'end_date',
            'total_amount',
        ];
    }

    /**
     * The canonical boilerplate every tenant starts from: the common
     * RENTAL AGREEMENT header (THE PARTIES clause) followed by the
     * standard business, rental, terms, and signature sections.
     */
    public static function defaultBoilerplate(): array
    {
        return [
            'title' => 'RENTAL AGREEMENT',
            'body' => <<<'TEXT'
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

                8. INDEMNIFICATION. The Renter shall indemnify and hold the Owner harmless from any loss, damage, or expense arising from the Renter's possession, use, or misuse of the Equipment.

                9. ENTIRE AGREEMENT. This Agreement constitutes the entire agreement between the Owner and the Renter concerning the subject matter hereof and supersedes all prior agreements, understandings, negotiations, and discussions, whether oral or written.

                10. GOVERNING LAW. This Agreement shall be governed under the laws located in the State of {{company_state}}.

                11. BUSINESS DETAILS
                Owner: {{business_name}}
                Address: {{business_address}}
                Phone: {{business_phone}} | Email: {{business_email}}

                12. RENTAL DETAILS
                Contract No: {{contract_number}}
                Rental period: {{start_date}} to {{end_date}}
                Total amount: {{total_amount}}

                13. TERMS
                13.1 The Renter agrees to return all items in the same condition, ordinary wear excepted.
                13.2 Late returns incur a late fee as stated in the footer below.
                13.3 The deposit paid will be applied against damage, loss, or late fees.
                13.4 The Renter is responsible for loss or damage from pickup until return.

                14. SIGNATURES
                Owner ({{business_name}}): ______________________  Date: __________
                Renter ({{customer_name}}): ______________________  Date: __________
                TEXT,
            'footer' => 'Late fee: $25/day per overdue item. Deposit is refundable less damages/fees.',
        ];
    }

    /**
     * Global boilerplate (business_id null). Used as the starting
     * point for every tenant.
     */
    public static function boilerplate(): ?self
    {
        return static::whereNull('business_id')->where('is_active', true)->first();
    }

    /**
     * Effective template for a business: its own customized copy,
     * falling back to the global boilerplate.
     */
    public static function effectiveFor(?int $businessId): ?self
    {
        if ($businessId) {
            $own = static::where('business_id', $businessId)->first();
            if ($own) {
                return $own;
            }
        }

        return static::boilerplate();
    }

    /**
     * Replace placeholders with tenant/business values.
     * Missing values keep the placeholder so the user can see what to fill.
     */
    public function render(array $values = []): array
    {
        $business = $this->business;
        if (! $business && ($values['business_id'] ?? null)) {
            $business = Business::find($values['business_id']);
        }

        $defaults = [
            'business_name' => $business?->name ?? $values['business_name'] ?? '{{business_name}}',
            'business_address' => $business?->address ?? $values['business_address'] ?? '',
            'business_phone' => $business?->phone ?? $values['business_phone'] ?? '',
            'business_email' => $business?->email ?? $values['business_email'] ?? '',
            'company_state' => $business?->state ?? $values['company_state'] ?? '{{company_state}}',
            'customer_name' => $values['customer_name'] ?? '{{customer_name}}',
            'customer_address' => $values['customer_address'] ?? '{{customer_address}}',
            'rented_items' => $values['rented_items'] ?? '{{rented_items}}',
            'contract_number' => $values['contract_number'] ?? '{{contract_number}}',
            'contract_date' => $values['contract_date'] ?? now()->format('F j, Y'),
            'start_date' => $values['start_date'] ?? '{{start_date}}',
            'end_date' => $values['end_date'] ?? '{{end_date}}',
            'total_amount' => $values['total_amount'] ?? '{{total_amount}}',
        ];

        $replace = function (?string $text) use ($defaults): string {
            if ($text === null) {
                return '';
            }
            foreach ($defaults as $key => $value) {
                $text = str_replace('{{' . $key . '}}', (string) $value, $text);
            }
            return $text;
        };

        return [
            'title' => $replace($this->title),
            'body' => $replace($this->body),
            'footer' => $replace($this->footer),
        ];
    }
}
