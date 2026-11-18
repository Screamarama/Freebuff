<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Theme extends Model
{
    /** Defaults match the original Breeze chrome, so unthemed tenants see no change. */
    public const DEFAULTS = [
        'primary_color' => '#1f2937',
        'secondary_color' => '#ffffff',
        'accent_color' => '#4f46e5',
        'text_color' => '#111827',
        'background_color' => '#f3f4f6',
    ];

    protected $fillable = [
        'business_id',
        'primary_color',
        'secondary_color',
        'accent_color',
        'text_color',
        'background_color',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Effective color values: stored theme overlaid on defaults,
     * so older rows missing keys still resolve.
     */
    public static function effectiveFor(?int $businessId): array
    {
        $stored = $businessId
            ? static::where('business_id', $businessId)->first()
            : null;

        return array_merge(
            static::DEFAULTS,
            array_intersect_key($stored?->only(array_keys(static::DEFAULTS)) ?? [], static::DEFAULTS)
        );
    }
}
