<?php

namespace App\Services\Support;

use App\Enums\ComplaintCategory;
use App\Enums\ComplaintStatus;
use App\Events\ComplaintReceived;
use App\Exceptions\DomainException;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Notifications\Notifier;
use App\Services\Tenancy\TenantSettings;
use App\Support\Realtime;
use Illuminate\Database\Eloquent\Model;

/** Structured grievance redressal (DFS §5.3) incl. transaction disputes & refund requests (Wallet §10). */
class ComplaintService
{
    public function __construct(private Notifier $notifier) {}

    public function open(Customer $customer, ComplaintCategory $category, string $subject, string $description, ?Model $related = null, string $channel = 'web', ?User $by = null): Complaint
    {
        if ($related && (int) $related->getAttribute('tenant_id') !== (int) $customer->tenant_id) {
            throw new DomainException('Invalid related record.');
        }
        $days = (int) TenantSettings::get('complaints', 'resolution_days', 10);
        $c = new Complaint([
            'ticket_no' => Numbering::next('complaint'), 'customer_id' => $customer->id, 'category' => $category, 'channel' => $channel,
            'subject' => $subject, 'description' => $description, 'related_type' => $related?->getMorphClass(), 'related_id' => $related?->getKey(),
            'priority' => in_array($category, [ComplaintCategory::TransactionDispute, ComplaintCategory::CollectionConduct], true) ? 'high' : 'normal',
            'sla_due_at' => now()->addDays($days),
        ]);
        $c->forceFill(['status' => ComplaintStatus::Open])->save();
        $c->messages()->create(['user_id' => $by?->id, 'body' => $description]);
        Auditor::record('complaint.opened', $c, [], ['ticket' => $c->ticket_no, 'category' => $category->value]);
        Realtime::emit(new ComplaintReceived($c->tenant_id, ['ticket_no' => $c->ticket_no, 'category' => $category->value]));
        $this->notifier->toCustomer($customer, 'complaint_opened', [
            'title' => 'Complaint '.$c->ticket_no.' received', 'body' => 'We will respond by '.$c->sla_due_at->format('d M Y').'. You can track it under Complaints & Support.',
        ]);

        return $c;
    }

    public function reply(Complaint $c, User $by, string $body, bool $internal = false): void
    {
        if ($by->isCustomer() && ((int) $by->customer_id !== (int) $c->customer_id || $internal)) {
            throw new DomainException('Not authorized.');
        }
        if (in_array($c->status, [ComplaintStatus::Closed], true)) {
            throw new DomainException('This complaint is closed.');
        }
        $c->messages()->create(['user_id' => $by->id, 'body' => $body, 'is_internal' => $internal]);
        if ($by->isStaff() && ! $internal && $c->customer) {
            $this->notifier->toCustomer($c->customer, 'complaint_reply', ['title' => 'Update on '.$c->ticket_no, 'body' => $body]);
        }
        if ($by->isCustomer() && $c->status === ComplaintStatus::Resolved) {
            $this->transition($c, ComplaintStatus::InProgress, $by, 'Reopened by customer');
        }
    }

    public function assign(Complaint $c, User $agent, User $by): void
    {
        $c->update(['assigned_to' => $agent->id]);
        Auditor::record('complaint.assigned', $c, [], ['to' => $agent->name]);
        $this->notifier->toUser($agent, 'complaint_assigned', ['title' => 'Complaint '.$c->ticket_no.' assigned to you', 'body' => $c->subject, 'url' => '/ops/complaints/'.$c->id]);
    }

    public function transition(Complaint $c, ComplaintStatus $to, User $by, ?string $note = null, ?string $resolution = null): Complaint
    {
        $c->status->assertCanTransitionTo($to, 'Complaint');
        if ($to === ComplaintStatus::Resolved && blank($resolution)) {
            throw new DomainException('Enter the resolution given to the customer.');
        }
        $from = $c->status;
        $c->forceFill(['status' => $to, 'acknowledged_at' => $c->acknowledged_at ?? ($to !== ComplaintStatus::Open ? now() : null),
            'resolved_at' => $to === ComplaintStatus::Resolved ? now() : $c->resolved_at, 'resolution' => $resolution ?? $c->resolution])->save();
        Auditor::record('complaint.status_changed', $c, ['status' => $from->value], ['status' => $to->value], $note ?? $resolution);
        if ($c->customer && in_array($to, [ComplaintStatus::Acknowledged, ComplaintStatus::Resolved, ComplaintStatus::Escalated], true)) {
            $this->notifier->toCustomer($c->customer, 'complaint_status', ['title' => $c->ticket_no.': '.$to->label(), 'body' => $resolution ?? $note ?? 'Status updated.']);
        }

        return $c;
    }
}
