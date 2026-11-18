<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

/**
 * Who may move money backwards. The agreement page and the void endpoint both
 * ask this policy, so the button and the server rule cannot drift apart.
 */
class PaymentPolicy
{
    /**
     * Voiding a receipt is an admin/manager action — clerks take money in, they
     * do not reverse it.
     */
    public function void(User $user, Payment $payment): bool
    {
        return in_array($user->role, ['admin', 'manager'], true);
    }
}
