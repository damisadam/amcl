<?php

namespace App\Services\Lending;

use App\Exceptions\DomainException;
use App\Models\Instalment;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Notifications\Notifier;
use App\Support\Money;

/** Single-instalment reminders sent by collections staff (complements the scheduled ServicingService::sendReminders). */
class CollectionsService
{
    public function __construct(private Notifier $notifier) {}

    public function sendReminder(Instalment $inst, User $by): void
    {
        $loan = $inst->loanAccount;
        if (! $loan->isOpen() || $inst->status->value === 'paid') {
            throw new DomainException('This instalment is not outstanding.');
        }
        $due = Money::sub(Money::add($inst->total_due, $inst->late_charge_due), $inst->total_paid);
        $when = $inst->due_date->isPast() ? 'overdue since '.$inst->due_date->format('d M Y') : 'due on '.$inst->due_date->format('d M Y');
        $this->notifier->toCustomer($loan->customer, 'instalment_reminder', [
            'title' => 'Instalment reminder',
            'body' => 'Instalment #'.$inst->number.' of '.Money::format($due).' for '.$loan->account_no.' is '.$when.'.',
            'url' => '/app/financing/'.$loan->id,
        ], ['in_app', 'sms']);
        Auditor::record('collections.reminder_sent', $loan, [], ['instalment' => $inst->number, 'amount' => $due, 'by' => $by->id]);
    }

    /** Aging bucket for days past due. */
    public static function bucket(int $days): string
    {
        return match (true) {
            $days <= 0 => 'current', $days <= 30 => '1-30', $days <= 60 => '31-60', $days <= 90 => '61-90', default => '90+',
        };
    }
}
