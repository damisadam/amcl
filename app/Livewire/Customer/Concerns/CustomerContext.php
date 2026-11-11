<?php

namespace App\Livewire\Customer\Concerns;

use App\Livewire\Concerns\HandlesActions;
use App\Models\Customer;
use App\Models\User;
use App\Models\Wallet;

/**
 * Common helpers for customer portal screens. Every lookup is anchored on the authenticated
 * customer, so URL tampering with other ids resolves to 404 (never another customer's data).
 */
trait CustomerContext
{
    use HandlesActions;

    protected function user(): User
    {
        $u = auth()->user();
        abort_unless($u && $u->isCustomer() && $u->customer_id, 403);

        return $u;
    }

    protected function customer(): Customer
    {
        return Customer::findOrFail($this->user()->customer_id);
    }

    protected function wallet(): ?Wallet
    {
        return $this->customer()->wallet()->first();
    }

    /** Abort 404 unless the model belongs to the logged-in customer. */
    protected function own($model, string $column = 'customer_id')
    {
        abort_unless($model && (int) $model->{$column} === (int) $this->user()->customer_id, 404);

        return $model;
    }

    public function demo(): bool
    {
        return (bool) \App\Services\Payments\Simulation::enabled();
    }
}
