<?php

namespace App\Support;

class Money
{
    /**
     * Symbols for the currencies the app can display. Anything else falls back
     * to the ISO code, so a tenant is never shown the wrong symbol.
     */
    private const SYMBOLS = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'AUD' => 'A$',
        'CAD' => 'C$',
        'NZD' => 'NZ$',
        'INR' => '₹',
        'ZAR' => 'R',
    ];

    /** The ISO codes the app formats with a symbol; the forms offer exactly these. */
    public static function codes(): array
    {
        return array_keys(self::SYMBOLS);
    }

    /** "$", "€", or "KES " for an unknown code — for client-side formatting too. */
    public static function symbol(?string $currency = null): string
    {
        $code = strtoupper((string) ($currency ?: 'USD'));

        return self::SYMBOLS[$code] ?? $code.' ';
    }

    /** "$1,240.00", "-$20.00", or "KES 1,240.00" for an unknown code. */
    public static function format(float|int|string|null $amount, ?string $currency = null): string
    {
        $amount = (float) $amount;
        $figure = number_format(abs($amount), 2);

        return ($amount < 0 && $figure !== '0.00' ? '-' : '')
            .self::symbol($currency)
            .$figure;
    }
}
