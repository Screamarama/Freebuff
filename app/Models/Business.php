<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    /** Whole-percentage sales tax fields: 7 means 7%. */
    public const SALES_TAX_FIELDS = ['state_sales_tax', 'county_sales_tax', 'city_sales_tax', 'vat'];

    protected $fillable = ['name', 'address', 'phone', 'email', 'logo_url', 'tax_id', 'currency', 'state', 'country', 'city', 'state_sales_tax', 'county_sales_tax', 'city_sales_tax', 'vat', 'is_active'];
    
    protected $casts = ['is_active' => 'boolean'];
    
    public function products(): HasMany { return $this->hasMany(Product::class); }
    public function customers(): HasMany { return $this->hasMany(Customer::class); }
    public function users(): HasMany { return $this->hasMany(User::class); }
    public function reservations(): HasMany { return $this->hasMany(Reservation::class); }
    public function rentalContracts(): HasMany { return $this->hasMany(RentalContract::class); }

    /**
     * The combined tax percentage this tenant charges: the three US sales tax
     * components, or VAT elsewhere (the request layer keeps the two exclusive).
     */
    public function taxRate(): float
    {
        return strtoupper((string) $this->country) === 'US'
            ? (float) $this->state_sales_tax + (float) $this->county_sales_tax + (float) $this->city_sales_tax
            : (float) $this->vat;
    }

    /** What this tenant's tax is called on a statement. */
    public function taxLabel(): string
    {
        return strtoupper((string) $this->country) === 'US' || blank($this->country)
            ? 'Sales tax'
            : 'VAT';
    }

    /**
     * Save submitted business details and log the fields that actually changed.
     * A save that changes nothing leaves no audit row behind.
     */
    public function applyDetails(array $attributes, ?User $actor = null): void
    {
        $attributes = self::taxesForCountry(
            $attributes,
            strtoupper((string) ($attributes['country'] ?? $this->country))
        );

        $changes = $this->changesFrom($attributes);

        $this->update($attributes);

        if ($changes === []) {
            return;
        }

        AuditLog::record(
            'tenant.updated',
            ['name' => $this->name, 'changes' => $changes],
            $actor,
            $this->id,
            $this,
        );
    }

    /**
     * Keep only the tax fields the country uses, clearing the other system so
     * a tenant that switched country cannot keep both and be taxed twice.
     *
     * @return array<string, mixed>
     */
    public static function taxesForCountry(array $attributes, string $country): array
    {
        if ($country === 'US') {
            $attributes['vat'] = null;
        } else {
            foreach (['state_sales_tax', 'county_sales_tax', 'city_sales_tax'] as $field) {
                $attributes[$field] = null;
            }
        }

        return $attributes;
    }

    /**
     * The submitted values that differ from what is stored, as old → new, so
     * an audit entry can say what a save actually changed.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function changesFrom(array $attributes): array
    {
        $changes = [];

        foreach ($attributes as $field => $value) {
            $current = $this->getAttribute($field);

            // String comparison treats null and '' as the same empty value,
            // which is what the forms produce.
            if ((string) $current === (string) $value) {
                continue;
            }

            $changes[$field] = ['from' => $current, 'to' => $value];
        }

        return $changes;
    }
}
