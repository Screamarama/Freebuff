<?php

namespace App\Policies;

use App\Models\RentalContract;
use App\Models\User;

/**
 * Money taken off a customer's account is a management decision. The agreement
 * page and the reverse-charge endpoint both ask this policy, so the button and
 * the server rule cannot drift apart.
 */
class RentalContractPolicy
{
    public function reverseCharge(User $user, RentalContract $contract): bool
    {
        return in_array($user->role, ['admin', 'manager'], true);
    }

    /** Changing what a closed return charged is a management correction. */
    public function adjustSettlement(User $user, RentalContract $contract): bool
    {
        return in_array($user->role, ['admin', 'manager'], true);
    }

    /** Putting deposit money back on a closed return is the same tier. */
    public function topUpDeposit(User $user, RentalContract $contract): bool
    {
        return in_array($user->role, ['admin', 'manager'], true);
    }

    /** Cancelling waives the bill and hands money back — management only. */
    public function cancelAgreement(User $user, RentalContract $contract): bool
    {
        return in_array($user->role, ['admin', 'manager'], true);
    }

    /** Paying a credit back to the customer is the same tier of decision. */
    public function refundCredit(User $user, RentalContract $contract): bool
    {
        return in_array($user->role, ['admin', 'manager'], true);
    }
}
