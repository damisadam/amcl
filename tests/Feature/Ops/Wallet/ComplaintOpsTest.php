<?php

namespace Tests\Feature\Ops\Wallet;

use App\Enums\ComplaintCategory;
use App\Livewire\Ops\Complaints\Index;
use App\Livewire\Ops\Complaints\Show;
use App\Services\Support\ComplaintService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class ComplaintOpsTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_complaint_lifecycle(): void
    {
        $t = $this->makeTenant('alpha');
        $cu = $this->customer($t, false);
        TenantContext::set($t);
        $c = app(ComplaintService::class)->open($cu->customer, ComplaintCategory::Service, 'Late response', 'Nobody called me back', null, 'web', $cu);
        $agent = $this->staff($t, 'customer_support');

        Livewire::actingAs($agent)->test(Index::class)->assertSee($c->ticket_no);
        Livewire::actingAs($agent)->test(Show::class, ['complaint' => $c])
            ->set('assignee', $agent->id)->call('assign')
            ->set('reply', 'We are looking into it.')->call('sendReply')->assertHasNoErrors();
        $this->assertSame('acknowledged', $c->fresh()->status->value);
        $this->assertSame($agent->id, $c->fresh()->assigned_to);

        // resolution text is mandatory
        Livewire::actingAs($agent)->test(Show::class, ['complaint' => $c->fresh()])->call('openTransition', 'resolved')->call('transition')->assertHasErrors('resolution');
        Livewire::actingAs($agent)->test(Show::class, ['complaint' => $c->fresh()])->call('openTransition', 'resolved')
            ->set('resolution', 'Called back and apologised.')->call('transition')->assertHasNoErrors();
        $this->assertSame('resolved', $c->fresh()->status->value);
        $this->assertNotNull($c->fresh()->resolved_at);
    }

    public function test_view_only_user_cannot_manage(): void
    {
        $t = $this->makeTenant('alpha');
        $cu = $this->customer($t, false);
        TenantContext::set($t);
        $c = app(ComplaintService::class)->open($cu->customer, ComplaintCategory::Service, 'x', 'y');
        $officer = $this->staff($t, 'loan_officer'); // complaints.view only
        Livewire::actingAs($officer)->test(Show::class, ['complaint' => $c])->set('reply', 'hi')->call('sendReply')->assertForbidden();
    }
}
