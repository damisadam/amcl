<?php

namespace Tests\Feature\Ops\Servicing;

use App\Livewire\Ops\Loans\Index;
use App\Livewire\Ops\Loans\Show;
use App\Livewire\Ops\Loans\Transfer;
use App\Models\Branch;
use App\Models\LoanPayment;
use App\Models\PortfolioTransfer;
use App\Support\TenantContext;
use Livewire\Livewire;

class LoanScreensTest extends ServicingTestCase
{
    public function test_manual_payment_is_allocated_and_reversal_respects_sod(): void
    {
        $t = $this->makeTenant('alpha');
        $loan = $this->disbursedLoan($t);
        $acct = $this->staff($t, 'branch_manager'); // records payments but has no repayments.reverse
        $due = $loan->instalments()->first()->total_due;

        Livewire::actingAs($acct)->test(Show::class, ['loan' => $loan])
            ->call('openPay')->set('payAmount', (string) $due)->set('payChannel', 'cash')->set('payRef', 'RCPT-1')->call('recordPayment')->assertHasNoErrors();
        $payment = LoanPayment::first();
        $this->assertSame('succeeded', $payment->status->value);
        $this->assertSame('paid', $loan->instalments()->first()->status->value);

        // A user without repayments.reverse is denied.
        Livewire::actingAs($acct)->test(Show::class, ['loan' => $loan])->call('openReverse', $payment->id)->assertForbidden();

        $admin = $this->staff($t, ['finance_officer', 'accountant']); // reversals are a finance decision (Tenant Admin no longer holds it)
        // SoD: this user records a second payment and is then refused when reversing it.
        Livewire::actingAs($admin)->test(Show::class, ['loan' => $loan])
            ->call('openPay')->set('payAmount', '1000')->set('payChannel', 'cash')->set('payRef', 'RCPT-2')->call('recordPayment');
        $own = LoanPayment::where('external_ref', 'RCPT-2')->first();
        Livewire::actingAs($admin)->test(Show::class, ['loan' => $loan])->call('openReverse', $own->id)->set('reverseReason', 'Mistake here')->call('reverse')->assertHasErrors('action');
        $this->assertSame('succeeded', $own->fresh()->status->value);

        Livewire::actingAs($admin)->test(Show::class, ['loan' => $loan])->call('openReverse', $payment->id)->set('reverseReason', 'Cheque bounced')->call('reverse');
        $this->assertSame('reversed', $payment->fresh()->status->value);
        $this->assertNotSame('paid', $loan->instalments()->first()->status->value);
    }

    public function test_list_scopes_to_tenant_and_loan_view_blocks_other_tenant(): void
    {
        $a = $this->makeTenant('alpha');
        $loanA = $this->disbursedLoan($a);
        $b = $this->makeTenant('bravo');
        $adminB = $this->admin($b);

        Livewire::actingAs($adminB)->test(Index::class)->assertDontSee($loanA->account_no);
        $this->actingAs($adminB)->get(route('ops.loans.show', $loanA->id))->assertNotFound();
        $this->actingAs($this->admin($a))->get(route('ops.loans.show', $loanA->id))->assertOk();
    }

    public function test_recovery_agent_cannot_open_unassigned_loan(): void
    {
        $t = $this->makeTenant('alpha');
        $loan = $this->disbursedLoan($t);
        $agent = $this->staff($t, 'recovery_agent');
        Livewire::actingAs($agent)->test(Show::class, ['loan' => $loan])->assertForbidden();
    }

    public function test_portfolio_transfer_moves_branch_with_audit(): void
    {
        $t = $this->makeTenant('alpha');
        $loan = $this->disbursedLoan($t);
        $new = TenantContext::run($t, fn () => Branch::first()->area->branches()->create(['code' => 'B2', 'name' => 'Model Town']));
        Livewire::actingAs($this->admin($t))->test(Transfer::class)
            ->set('selected', [(string) $loan->id])->set('toBranch', (string) $new->id)->set('reason', 'Branch restructuring')->call('transfer')->assertHasNoErrors();
        $this->assertSame($new->id, $loan->fresh()->branch_id);
        $this->assertSame(1, PortfolioTransfer::count());
        Livewire::actingAs($this->staff($t, 'loan_officer'))->test(Transfer::class)->assertForbidden();
    }
}
