<?php

namespace Tests\Feature;

use App\Support\Money;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_formats_in_the_tenant_currency(): void
    {
        $this->assertSame('$1,234.50', Money::format(1234.5, 'USD'));
        $this->assertSame('€1,234.50', Money::format(1234.5, 'EUR'));
        $this->assertSame('£0.00', Money::format(0, 'GBP'));
    }

    public function test_it_lists_the_codes_the_forms_offer(): void
    {
        $this->assertSame(['USD', 'EUR', 'GBP', 'AUD', 'CAD', 'NZD', 'INR', 'ZAR'], Money::codes());
    }

    public function test_it_falls_back_to_the_code_for_unknown_currencies(): void
    {
        $this->assertSame('KES 1,000.00', Money::format(1000, 'KES'));
    }

    public function test_negative_amounts_keep_the_sign_before_the_symbol(): void
    {
        $this->assertSame('-$20.00', Money::format(-20, 'USD'));
        $this->assertSame('-€20.00', Money::format(-20, 'EUR'));
    }
}
