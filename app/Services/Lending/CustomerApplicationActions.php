<?php

namespace App\Services\Lending;

use App\Exceptions\DomainException;
use App\Models\Objection;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Notifications\Notifier;

/** Customer-side responses on their own application (objection replies). */
class CustomerApplicationActions
{
    public function respondToObjection(Objection $o, User $customer, string $response): Objection
    {
        if (! $customer->isCustomer() || (int) $o->application->customer_id !== (int) $customer->customer_id || ! $o->visible_to_customer) {
            throw new DomainException(__('Not authorized.'));
        }
        if ($o->status === 'resolved') {
            throw new DomainException(__('This objection is already resolved.'));
        }
        $o->update(['response' => $response, 'status' => 'responded', 'seen_at' => $o->seen_at ?? now()]);
        Auditor::record('application.objection_responded', $o->application, [], ['objection_id' => $o->id], $response);
        if ($o->raiser) {
            app(Notifier::class)->toUser($o->raiser, 'objection_response', [
                'title' => 'Customer responded on '.$o->application->application_no, 'body' => $response, 'url' => '/ops/applications/'.$o->loan_application_id,
            ]);
        }

        return $o;
    }
}
