<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuditLog extends Model
{
    /**
     * The table only records when an event happened, never when it was edited.
     */
    const UPDATED_AT = null;

    /**
     * Every action this log can hold, in the order the filter offers them.
     */
    public const ACTIONS = [
        'auth.login' => 'Signed in',
        'password.changed' => 'Password changed',
        'password.reset' => 'Password reset via email',
        'tenant.user.password_reset' => 'Password reset by admin',
        'tenant.user.activated' => 'Login activated',
        'tenant.user.deactivated' => 'Login deactivated',
        'tenant.updated' => 'Business details updated',
        'customer.tax_exemption_updated' => 'Tax exemption updated',
        'tenant.activated' => 'Tenant activated',
        'tenant.suspended' => 'Tenant suspended',
        'payment.recorded' => 'Payment received',
        'payment.voided' => 'Payment voided',
        'deposit.recorded' => 'Deposit received',
        'deposit.settled' => 'Deposit settled',
        'agreement.cancelled' => 'Agreement cancelled',
        'account.charged' => 'Charged to account',
        'account.charge_reversed' => 'Charge reversed',
        'deposit.adjusted' => 'Return adjusted',
        'deposit.top_up' => 'Deposit top-up',
        'payment.refunded' => 'Credit refunded',
        'account.payment_recorded' => 'Account payment taken',
        'account.payment_voided' => 'Account payment voided',
    ];

    protected $fillable = [
        'user_id',
        'business_id',
        'action',
        'model_type',
        'model_id',
        'details',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Write an entry, snapshotting the actor (and the tenant's name) so the
     * history still reads correctly once those rows are deleted. The foreign
     * keys go null in that case, the snapshot does not.
     */
    public static function record(
        string $action,
        array $details = [],
        ?User $actor = null,
        ?int $businessId = null,
        ?Model $subject = null,
    ): self {
        $actor ??= Auth::user();
        $request = request();

        return static::create([
            'user_id' => $actor?->getAuthIdentifier(),
            'business_id' => $businessId ?? $actor?->business_id,
            'action' => $action,
            'model_type' => $subject?->getMorphClass(),
            'model_id' => $subject?->getKey(),
            'details' => array_merge(
                array_filter([
                    'actor_name' => $actor?->name,
                    'actor_email' => $actor?->email,
                ]),
                $details
            ),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
        ]);
    }

    /**
     * Who performed the action, falling back to the snapshot captured at the
     * time because the user row may since have been deleted.
     */
    public function actorName(): ?string
    {
        return $this->user?->name ?? ($this->details['actor_name'] ?? null);
    }

    public function actorEmail(): ?string
    {
        return $this->user?->email ?? ($this->details['actor_email'] ?? null);
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action]
            ?? ucfirst(str_replace(['.', '_'], ' ', $this->action));
    }

    /**
     * Colour family for the badge: blocked/removed things read as danger,
     * routine account activity stays neutral.
     */
    public function actionTone(): string
    {
        return match ($this->action) {
            'tenant.suspended', 'tenant.user.deactivated', 'payment.voided', 'customer.tax_exemption_updated' => 'danger',
            'tenant.activated', 'tenant.user.activated' => 'success',
            default => 'neutral',
        };
    }

    /**
     * The account an entry is about, when that is someone other than the actor
     * (e.g. a super admin resetting a staff member's password).
     */
    /** "Name: Old → New; Currency: USD → EUR" for an entry that lists changes. */
    public function changeSummary(): ?string
    {
        $changes = $this->details['changes'] ?? null;

        if (! is_array($changes) || $changes === []) {
            return null;
        }

        return collect($changes)
            ->map(fn (array $change, string $field) => ucfirst(str_replace('_', ' ', $field)).': '
                .$this->changeValue($change['from'] ?? null).' → '.$this->changeValue($change['to'] ?? null))
            ->implode('; ');
    }

    private function changeValue(mixed $value): string
    {
        return ($value === null || $value === '') ? '(empty)' : (string) $value;
    }

    public function subjectLabel(): ?string
    {
        $email = $this->details['subject_email'] ?? null;

        if ($email === null || $email === $this->actorEmail()) {
            return null;
        }

        $name = $this->details['subject_name'] ?? null;

        return $name ? $name.' · '.$email : $email;
    }
}
